<?php
// Ported from inboundCallcode.txt (LiveKit + Plivo helpers)

function base64UrlEncode($data) {

    $base64    = base64_encode($data);

    $base64Url = strtr($base64, '+/', '-_');

    return rtrim($base64Url, '=');

}

function generateLiveKitToken($apiKey, $apiSecret, $ttl = 3600) {

    $payload = [

        'exp' => time() + $ttl,

        'nbf' => time(),

        'iss' => $apiKey,

        'sub' => 'admin',

        'sip' => [

            'admin' => true,

        ],

    ];

    $header         = ['alg' => 'HS256', 'typ' => 'JWT'];

    $base64Header   = base64UrlEncode(json_encode($header));

    $base64Payload  = base64UrlEncode(json_encode($payload));

    $signature      = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $apiSecret, true);

    $base64Signature = base64UrlEncode($signature);

    return $base64Header . '.' . $base64Payload . '.' . $base64Signature;

}

function httpPostJson($endpoint, $token, $body, &$debugLog = null) {

    $jsonData = json_encode($body);

    if ($jsonData === false) {

        throw new Exception('Failed to encode JSON: ' . json_last_error_msg());

    }

    $logEntry = [

        'endpoint'      => $endpoint,

        'request_body'  => $body,

        'request_json'  => $jsonData,

        'token_preview' => substr($token, 0, 50) . '...',

    ];

    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST           => true,

        CURLOPT_HTTPHEADER     => [

            'Authorization: Bearer ' . $token,

            'Content-Type: application/json',

        ],

        CURLOPT_POSTFIELDS     => $jsonData,

        CURLOPT_TIMEOUT        => 30,

        CURLOPT_FAILONERROR    => false,

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2,

    ]);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $err      = curl_error($ch);

    curl_close($ch);

    $logEntry['http_code']        = $httpCode;

    $logEntry['response_raw']     = $response;

    $logEntry['response_length']  = strlen($response);

    $logEntry['curl_error']       = $err ?: null;

    if ($response === false) {

        if ($debugLog !== null) {

            $debugLog[] = $logEntry;

        }

        throw new Exception('HTTP error: ' . $err);

    }

    $trimmedResponse = trim($response);

    $isJson          = preg_match('/^[\s]*[\[\{]/', $trimmedResponse);

    if (!$isJson) {

        $logEntry['response_type']    = 'non-json';

        $logEntry['response_preview'] = substr($trimmedResponse, 0, 200);

        if ($trimmedResponse === 'OK' || $trimmedResponse === '') {

            if ($debugLog !== null) {

                $debugLog[] = $logEntry;

            }

            throw new Exception(

                'API returned non-JSON response "' . $trimmedResponse .

                '". This usually indicates an authentication error, wrong endpoint, or server issue. HTTP Code: ' . $httpCode

            );

        }

        if ($httpCode >= 200 && $httpCode < 300) {

            if ($debugLog !== null) {

                $debugLog[] = $logEntry;

            }

            return [

                'raw_response' => $response,

                'http_code'    => $httpCode,

                'note'         => 'Non-JSON response received',

            ];

        }

    }

    $data = json_decode($response, true);

    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {

        $logEntry['json_error'] = json_last_error_msg();

        if ($debugLog !== null) {

            $debugLog[] = $logEntry;

        }

        if ($httpCode >= 200 && $httpCode < 300) {

            throw new Exception(

                'Expected JSON response but got: ' . substr($trimmedResponse, 0, 200) .

                ' (HTTP ' . $httpCode . ')'

            );

        }

        throw new Exception('Invalid JSON response: ' . json_last_error_msg() . ' Raw: ' . substr($response, 0, 500));

    }

    $logEntry['response_parsed'] = $data;

    if ($debugLog !== null) {

        $debugLog[] = $logEntry;

    }

    if ($httpCode >= 400) {

        $msg = isset($data['message']) ? $data['message'] : 'Unknown error';

        throw new Exception('API error (HTTP ' . $httpCode . '): ' . $msg . ' Full: ' . $response);

    }

    return $data;

}

function createInboundTrunk($serverUrl, $token, $trunkName, $phoneNumber, &$debugLog = null) {

    $endpoint = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/CreateSIPInboundTrunk';

    $body     = [

        'trunk' => [

            'name'    => $trunkName,

            'numbers' => [$phoneNumber],

        ],

    ];

    $response   = httpPostJson($endpoint, $token, $body, $debugLog);

    $hasNested  = isset($response['inbound_trunk']) || isset($response['trunk']);

    $hasFlat    = isset($response['sip_trunk_id']) || isset($response['name']);

    $hasRawResp = isset($response['raw_response']);

    if (!$hasNested && !$hasFlat && !$hasRawResp) {

        throw new Exception(

            'Trunk creation response missing trunk data. Response: ' .

            json_encode($response, JSON_PRETTY_PRINT)

        );

    }

    return $response;

}

// Delete a specific SIP trunk by ID

function deleteSipTrunkById($serverUrl, $token, $trunkId, &$debugLog = null) {

    $endpoint = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/DeleteSIPTrunk';

    $body = ['sip_trunk_id' => $trunkId];

    $response = httpPostJson($endpoint, $token, $body, $debugLog);

    

    if ($debugLog !== null) {

        $debugLog[] = [

            'step' => 'delete_trunk_by_id',

            'trunk_id' => $trunkId,

            'result' => $response,

        ];

    }

    

    return $response;

}

function createSipDispatchRuleIndividual($serverUrl, $token, $ruleName, $agentName, $agentMetadataJson, $trunkIdOrNull, &$debugLog = null, $apiTools = null) {

    $endpoint = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/CreateSIPDispatchRule';

    $roomConfig = [

        'agents' => [

            [

                'agent_name' => $agentName,

                'metadata'   => $agentMetadataJson,

            ],

        ],

    ];

    // Add api_tools to room_config if provided

    if ($apiTools !== null && $apiTools !== '') {

        // api_tools from database is a JSON string with escaped characters

        // Decode it once to get the actual structure

        if (is_string($apiTools)) {

            $decodedTools = json_decode($apiTools, true);

            if ($decodedTools !== null && json_last_error() === JSON_ERROR_NONE) {

                // If decoded result has 'api_tools' key, extract it (nested structure)

                // Otherwise use the decoded value directly

                if (isset($decodedTools['api_tools']) && is_array($decodedTools['api_tools'])) {

                    $roomConfig['api_tools'] = $decodedTools['api_tools'];

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'api_tools_added_nested', 'count' => count($decodedTools['api_tools'])];

                    }

                } elseif (is_array($decodedTools)) {

                    // Use decoded value as-is (already the array structure)

                    $roomConfig['api_tools'] = $decodedTools;

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'api_tools_added_direct', 'count' => count($decodedTools)];

                    }

                } else {

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'api_tools_not_array', 'decoded_type' => gettype($decodedTools)];

                    }

                }

            } else {

                // If JSON decode fails, log and skip (don't use invalid data)

                if ($debugLog !== null) {

                    $debugLog[] = [

                        'step' => 'api_tools_decode_failed', 

                        'error' => json_last_error_msg(), 

                        'error_code' => json_last_error(),

                        'raw_value_preview' => substr($apiTools, 0, 200)

                    ];

                }

            }

        } else {

            // Already decoded/array, use directly

            $roomConfig['api_tools'] = $apiTools;

            if ($debugLog !== null) {

                $debugLog[] = ['step' => 'api_tools_added_as_is', 'type' => gettype($apiTools)];

            }

        }

    } else {

        if ($debugLog !== null) {

            $debugLog[] = ['step' => 'api_tools_skipped', 'reason' => $apiTools === null ? 'null' : 'empty_string'];

        }

    }

    $rule = [

        'rule' => [

            'dispatch_rule_individual' => [

                'room_prefix' => 'call-',

            ],

        ],

        'name'        => $ruleName,

        'room_config' => $roomConfig,

    ];

    if ($trunkIdOrNull) {

        $rule['trunk_ids'] = [$trunkIdOrNull];

    }

    $body     = ['dispatch_rule' => $rule];

    $response = httpPostJson($endpoint, $token, $body, $debugLog);

    if (!isset($response['dispatch_rule']) && !isset($response['rule']) && !isset($response['raw_response'])) {

        throw new Exception(

            'Dispatch rule creation response missing rule data. Response: ' .

            json_encode($response, JSON_PRETTY_PRINT)

        );

    }

    return $response;

}

// List dispatch rules to find existing rule for this agent/phone

function listSipDispatchRules($serverUrl, $token, &$debugLog = null) {

    $endpoint = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/ListSIPDispatchRule';

    $body     = (object)[];

    $response = httpPostJson($endpoint, $token, $body, $debugLog);

    

    // Log the raw response structure for debugging

    if ($debugLog !== null) {

        $debugLog[] = [

            'step' => 'list_dispatch_rules_response',

            'response_keys' => is_array($response) ? array_keys($response) : 'not_an_array',

            'response_type' => gettype($response),

            'has_dispatch_rules_key' => is_array($response) && isset($response['dispatch_rules']),

            'is_array' => is_array($response),

            'response_sample' => is_array($response) && count($response) > 0 ? array_slice($response, 0, 1) : 'empty_or_not_array'

        ];

    }

    

    return $response;

}

// Delete a dispatch rule

function deleteSipDispatchRule($serverUrl, $token, $ruleId, &$debugLog = null) {

    $endpoint = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/DeleteSIPDispatchRule';

    $body = [

        'sip_dispatch_rule_id' => $ruleId

    ];

    

    if ($debugLog !== null) {

        $debugLog[] = ['step' => 'delete_dispatch_rule', 'rule_id' => $ruleId];

    }

    

    $response = httpPostJson($endpoint, $token, $body, $debugLog);

    return $response;

}

// Delete dispatch rules associated with a trunk ID

function deleteDispatchRulesForTrunk($serverUrl, $token, $trunkId, &$debugLog = null) {

    if (!$trunkId) {

        return;

    }

    

    // List all dispatch rules

    $allRules = listSipDispatchRules($serverUrl, $token, $debugLog);

    

    // Handle different response formats

    $rules = [];

    if (isset($allRules['dispatch_rules']) && is_array($allRules['dispatch_rules'])) {

        $rules = $allRules['dispatch_rules'];

    } elseif (is_array($allRules) && !isset($allRules['dispatch_rules'])) {

        $rules = $allRules;

    }

    

    $deletedCount = 0;

    foreach ($rules as $rule) {

        $ruleTrunkIds = $rule['trunk_ids'] ?? [];

        if (isset($rule['trunk_ids']['set']) && is_array($rule['trunk_ids']['set'])) {

            $ruleTrunkIds = $rule['trunk_ids']['set'];

        }

        

        // Check if this rule is associated with the trunk

        if (in_array($trunkId, $ruleTrunkIds, true)) {

            $ruleId = $rule['sip_dispatch_rule_id'] ?? ($rule['id'] ?? null);

            if ($ruleId) {

                try {

                    deleteSipDispatchRule($serverUrl, $token, $ruleId, $debugLog);

                    $deletedCount++;

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'delete_dispatch_rule_for_trunk', 'rule_id' => $ruleId, 'trunk_id' => $trunkId];

                    }

                } catch (Exception $e) {

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'delete_dispatch_rule_error', 'rule_id' => $ruleId, 'error' => $e->getMessage()];

                    }

                }

            }

        }

    }

    

    return $deletedCount;

}

// Delete dispatch rules for a phone number (by finding associated trunk first)

function deleteDispatchRulesForNumber($serverUrl, $token, $phoneNumber, &$debugLog = null) {

    // First, find the trunk for this phone number

    $endpointList = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/ListSIPInboundTrunk';

    $listBody = (object)[];

    $listRes = httpPostJson($endpointList, $token, $listBody, $debugLog);

    

    $trunks = [];

    if (isset($listRes['trunks']) && is_array($listRes['trunks'])) {

        $trunks = $listRes['trunks'];

    } elseif (is_array($listRes)) {

        $trunks = $listRes;

    }

    

    foreach ($trunks as $trunk) {

        $numbers = [];

        if (isset($trunk['numbers']) && is_array($trunk['numbers'])) {

            $numbers = array_merge($numbers, $trunk['numbers']);

        }

        if (isset($trunk['numbers']['set']) && is_array($trunk['numbers']['set'])) {

            $numbers = array_merge($numbers, $trunk['numbers']['set']);

        }

        

        if (in_array($phoneNumber, $numbers, true)) {

            $trunkId = $trunk['sip_trunk_id'] ?? ($trunk['id'] ?? ($trunk['sid'] ?? null));

            if ($trunkId) {

                // Delete dispatch rules for this trunk

                return deleteDispatchRulesForTrunk($serverUrl, $token, $trunkId, $debugLog);

            }

        }

    }

    

    return 0;

}

// Update dispatch rule metadata with fresh agent data

// According to LiveKit API: UpdateSIPDispatchRule

// Format: { sip_dispatch_rule_id: "...", action: { replace: SIPDispatchRuleInfo } }

function updateSipDispatchRuleMetadata($serverUrl, $token, $ruleId, $agentName, $agentMetadataJson, $trunkId, &$debugLog = null, $apiTools = null) {

    $endpoint = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/UpdateSIPDispatchRule';

    // Get existing rule to preserve other settings

    $allRules = listSipDispatchRules($serverUrl, $token, $debugLog);

    $existingRule = null;

    

    // Handle different response formats

    $rules = [];

    if (isset($allRules['dispatch_rules']) && is_array($allRules['dispatch_rules'])) {

        $rules = $allRules['dispatch_rules'];

    } elseif (is_array($allRules) && !isset($allRules['dispatch_rules'])) {

        $rules = $allRules;

    }

    

    foreach ($rules as $rule) {

        $ruleIdMatch = $rule['sip_dispatch_rule_id'] ?? ($rule['id'] ?? '');

        if ($ruleIdMatch === $ruleId) {

            $existingRule = $rule;

            break;

        }

    }

    if (!$existingRule) {

        throw new Exception('Could not find existing dispatch rule with ID: ' . $ruleId);

    }

    // Build updated rule with fresh metadata, preserving existing structure

    // According to LiveKit API docs: UpdateSIPDispatchRuleRequest

    // Format: { sip_dispatch_rule_id: "...", action: SIPDispatchRuleInfo }

    // For replacement, action should be the full SIPDispatchRuleInfo object

    

    // Extract room_prefix from existing rule if available

    $roomPrefix = 'call-';

    if (isset($existingRule['rule']) && 

        isset($existingRule['rule']['dispatch_rule_individual']) && 

        isset($existingRule['rule']['dispatch_rule_individual']['room_prefix'])) {

        $roomPrefix = $existingRule['rule']['dispatch_rule_individual']['room_prefix'];

    }

    

    $roomConfig = [

        'agents' => [

            [

                'agent_name' => $agentName,

                'metadata'   => $agentMetadataJson, // Fresh metadata

            ],

        ],

    ];

    // Add api_tools to room_config: use provided value if available, otherwise preserve from existing rule

    if ($apiTools !== null && $apiTools !== '') {

        // api_tools from database is a JSON string with escaped characters

        // Decode it once to get the actual structure

        if (is_string($apiTools)) {

            $decodedTools = json_decode($apiTools, true);

            if ($decodedTools !== null && json_last_error() === JSON_ERROR_NONE) {

                // If decoded result has 'api_tools' key, extract it (nested structure)

                // Otherwise use the decoded value directly

                if (isset($decodedTools['api_tools']) && is_array($decodedTools['api_tools'])) {

                    $roomConfig['api_tools'] = $decodedTools['api_tools'];

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'update_api_tools_added_nested', 'count' => count($decodedTools['api_tools'])];

                    }

                } elseif (is_array($decodedTools)) {

                    // Use decoded value as-is (already the array structure)

                    $roomConfig['api_tools'] = $decodedTools;

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'update_api_tools_added_direct', 'count' => count($decodedTools)];

                    }

                } else {

                    if ($debugLog !== null) {

                        $debugLog[] = ['step' => 'update_api_tools_not_array', 'decoded_type' => gettype($decodedTools)];

                    }

                }

            } else {

                // If JSON decode fails, log and skip (don't use invalid data)

                if ($debugLog !== null) {

                    $debugLog[] = [

                        'step' => 'update_api_tools_decode_failed', 

                        'error' => json_last_error_msg(), 

                        'error_code' => json_last_error(),

                        'raw_value_preview' => substr($apiTools, 0, 200)

                    ];

                }

            }

        } else {

            // Already decoded/array, use directly

            $roomConfig['api_tools'] = $apiTools;

            if ($debugLog !== null) {

                $debugLog[] = ['step' => 'update_api_tools_added_as_is', 'type' => gettype($apiTools)];

            }

        }

    } elseif (isset($existingRule['room_config']['api_tools'])) {

        // Preserve api_tools from existing rule if no new value provided

        $roomConfig['api_tools'] = $existingRule['room_config']['api_tools'];

        if ($debugLog !== null) {

            $debugLog[] = ['step' => 'update_api_tools_preserved_from_existing'];

        }

    } else {

        if ($debugLog !== null) {

            $debugLog[] = ['step' => 'update_api_tools_skipped', 'reason' => $apiTools === null ? 'null' : ($apiTools === '' ? 'empty_string' : 'not_provided')];

        }

    }

    $updatedRule = [

        'rule' => [

            'dispatch_rule_individual' => [

                'room_prefix' => $roomPrefix,

            ],

        ],

        'name'        => $existingRule['name'] ?? 'dispatch-for-trunk',

        'room_config' => $roomConfig,

    ];

    // Preserve trunk_ids from existing rule

    if ($trunkId) {

        $updatedRule['trunk_ids'] = [$trunkId];

    } elseif (isset($existingRule['trunk_ids']) && is_array($existingRule['trunk_ids'])) {

        $updatedRule['trunk_ids'] = $existingRule['trunk_ids'];

    }

    // Preserve other fields from existing rule

    if (isset($existingRule['inbound_numbers'])) {

        $updatedRule['inbound_numbers'] = $existingRule['inbound_numbers'];

    }

    if (isset($existingRule['metadata'])) {

        $updatedRule['metadata'] = $existingRule['metadata'];

    }

    if (isset($existingRule['attributes'])) {

        $updatedRule['attributes'] = $existingRule['attributes'];

    }

    if (isset($existingRule['hide_phone_number'])) {

        $updatedRule['hide_phone_number'] = $existingRule['hide_phone_number'];

    }

    if (isset($existingRule['headers'])) {

        $updatedRule['headers'] = $existingRule['headers'];

    }

    if (isset($existingRule['room_preset'])) {

        $updatedRule['room_preset'] = $existingRule['room_preset'];

    }

    // According to LiveKit API docs: action should be the full SIPDispatchRuleInfo object directly

    $body = [

        'sip_dispatch_rule_id' => $ruleId,

        'action' => $updatedRule

    ];

    

    if ($debugLog !== null) {

        $debugLog[] = [

            'step' => 'update_dispatch_rule_body',

            'rule_id' => $ruleId,

            'existing_rule_name' => $existingRule['name'] ?? 'unknown',

            'body' => $body

        ];

    }

    

    $response = httpPostJson($endpoint, $token, $body, $debugLog);

    return $response;

}

// ===== Plivo API Functions =====

// Make Plivo API request (Basic Auth)

function plivoApiRequest($method, $endpoint, $data = null, &$debugLog = null, $useZentrunkApi = false) {

    global $plivoAuthId, $plivoAuthToken, $plivoApiUrl;

    

    if (empty($plivoAuthId) || empty($plivoAuthToken)) {

        throw new Exception('Plivo credentials not configured. Set PLIVO_AUTH_ID and PLIVO_AUTH_TOKEN.');

    }

    

    // Use Zentrunk API for trunk operations, regular API for endpoints/phone numbers

    if ($useZentrunkApi) {

        $baseUrl = 'https://api.plivo.com/v1/Zentrunk/Account';

    } else {

        $baseUrl = $plivoApiUrl;

    }

    

    $url = rtrim($baseUrl, '/') . '/' . $plivoAuthId . '/' . ltrim($endpoint, '/');

    

    $logEntry = [

        'method'   => $method,

        'endpoint' => $url,

        'data'     => $data,

    ];

    

    $ch = curl_init($url);

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,

        CURLOPT_USERPWD        => $plivoAuthId . ':' . $plivoAuthToken,

        CURLOPT_TIMEOUT        => 30,

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2,

    ]);

    

    if ($method === 'POST') {

        curl_setopt($ch, CURLOPT_POST, true);

        if ($data !== null) {

            $jsonData = json_encode($data);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $logEntry['request_json'] = $jsonData;

        }

    } elseif ($method === 'PUT') {

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');

        if ($data !== null) {

            $jsonData = json_encode($data);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $logEntry['request_json'] = $jsonData;

        }

    } elseif ($method === 'DELETE') {

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    }

    

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $err      = curl_error($ch);

    curl_close($ch);

    

    $logEntry['http_code']   = $httpCode;

    $logEntry['response_raw'] = $response;

    $logEntry['curl_error']   = $err ?: null;

    

    if ($response === false) {

        if ($debugLog !== null) {

            $debugLog[] = $logEntry;

        }

        throw new Exception('Plivo API HTTP error: ' . $err);

    }

    

    $data = json_decode($response, true);

    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {

        $logEntry['json_error'] = json_last_error_msg();

        if ($debugLog !== null) {

            $debugLog[] = $logEntry;

        }

        throw new Exception('Plivo API invalid JSON response: ' . json_last_error_msg());

    }

    

    $logEntry['response_parsed'] = $data;

    if ($debugLog !== null) {

        $debugLog[] = $logEntry;

    }

    

    if ($httpCode >= 400) {

        $msg = isset($data['error']) ? $data['error'] : (isset($data['message']) ? $data['message'] : 'Unknown error');

        throw new Exception('Plivo API error (HTTP ' . $httpCode . '): ' . $msg);

    }

    

    return $data;

}

// Get LiveKit SIP endpoint - use configured value or extract from server URL

// Format for Plivo: <subdomain>.sip.livekit.cloud;transport=tcp (without sip: prefix)

function getLiveKitSipEndpoint($livekitServerUrl, &$debugLog = null) {

    global $livekitSipEndpoint;

    

    // If SIP endpoint is configured directly, use it

    if (!empty($livekitSipEndpoint)) {

        // Remove sip: prefix if present and ensure transport is added

        $sipEndpoint = $livekitSipEndpoint;

        $sipEndpoint = preg_replace('/^sip:/', '', $sipEndpoint); // Remove sip: prefix

        

        // Add transport if not present

        if (strpos($sipEndpoint, ';transport=') === false) {

            $sipEndpoint .= ';transport=tcp';

        }

        

        if ($debugLog !== null) {

            $debugLog[] = [

                'step' => 'using_configured_sip_endpoint',

                'configured' => $livekitSipEndpoint,

                'formatted' => $sipEndpoint,

            ];

        }

        

        return $sipEndpoint;

    }

    

    // Otherwise extract from server URL

    $host = parse_url($livekitServerUrl, PHP_URL_HOST);

    

    if (!$host) {

        throw new Exception('Could not extract host from LiveKit server URL and no SIP endpoint configured');

    }

    

    // Replace .livekit.cloud with .sip.livekit.cloud and add transport

    $sipEndpoint = str_replace('.livekit.cloud', '.sip.livekit.cloud', $host) . ';transport=tcp';

    

    if ($debugLog !== null) {

        $debugLog[] = [

            'step' => 'extract_livekit_sip_endpoint',

            'server_url' => $livekitServerUrl,

            'host' => $host,

            'sip_endpoint' => $sipEndpoint,

        ];

    }

    

    return $sipEndpoint;

}

// Create inbound SIP trunk in Plivo pointing to LiveKit SIP endpoint

// According to Plivo docs: Create inbound trunk with LiveKit SIP endpoint as Primary URI

function createPlivoInboundTrunk($trunkName, $livekitSipEndpoint, &$debugLog = null) {

    // According to Plivo docs, the Primary URI should be the LiveKit SIP endpoint

    // Format: <subdomain>.sip.livekit.cloud;transport=tcp

    // We'll use the endpoint directly as the primary_uri (not UUID)

    

    // Create inbound trunk using Zentrunk API

    // The trunk should have the LiveKit SIP endpoint as Primary URI

    $trunkData = [

        'name' => $trunkName,

        'trunk_direction' => 'inbound',

        'primary_uri' => $livekitSipEndpoint, // Direct SIP URI, not UUID

    ];

    

    if ($debugLog !== null) {

        $debugLog[] = ['step' => 'plivo_trunk_creation_data', 'data' => $trunkData];

    }

    

    // Use Zentrunk API endpoint

    $trunkResponse = plivoApiRequest('POST', 'Trunk/', $trunkData, $debugLog, true);

    return $trunkResponse;

}

// Update phone number with specific trunk ID (manual update function)

function updatePhoneNumberWithTrunkId($phoneNumber, $trunkId, &$debugLog = null) {

    return connectPhoneNumberToTrunk($phoneNumber, $trunkId, $debugLog);

}

// Get phone number details from Plivo

function getPlivoPhoneNumber($phoneNumber, &$debugLog = null) {

    // Remove + and format for Plivo API

    // Endpoint: /Number/{number}/ (not PhoneNumber/)

    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

    $response    = plivoApiRequest('GET', 'Number/' . $cleanNumber . '/', null, $debugLog);

    return $response;

}

// Connect phone number to inbound trunk

// According to Plivo API: Update an account phone number

// API Endpoint: POST https://api.plivo.com/v1/Account/{auth_id}/Number/{number}/

// For Zentrunk customers: Use the specific Zentrunk app_id to map the number to the inbound trunk

function connectPhoneNumberToTrunk($phoneNumber, $trunkId, &$debugLog = null) {

    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

    

    // Use the specific Zentrunk app_id for proper inbound phone number attachment with Plivo trunk

    $zentrunkAppId = getenv('PLIVO_ZENTRUNK_APP_ID') ?: '12349622293949689';

    $data = [

        'app_id' => $zentrunkAppId, // Zentrunk app_id for proper inbound phone number attachment

    ];

    

    if ($debugLog !== null) {

        $debugLog[] = [

            'step' => 'plivo_phone_update_data',

            'phone' => $cleanNumber,

            'trunk_id' => $trunkId,

            'app_id' => $zentrunkAppId,

            'data' => $data,

            'note' => 'Using Zentrunk app_id for proper inbound phone number attachment with trunk'

        ];

    }

    

    // POST to update the phone number

    // Endpoint: POST /v1/Account/{auth_id}/Number/{number}/

    $response = plivoApiRequest('POST', 'Number/' . $cleanNumber . '/', $data, $debugLog);

    

    if ($debugLog !== null) {

        $debugLog[] = [

            'step' => 'plivo_phone_update_response',

            'response' => $response,

            'success' => isset($response['app_id']) && $response['app_id'] == $zentrunkAppId

        ];

    }

    

    return $response;

}

// List Plivo trunks

function listPlivoTrunks(&$debugLog = null) {

    $response = plivoApiRequest('GET', 'Trunk/', null, $debugLog, true);

    return $response;

}

// Delete Plivo SIP trunk

function deletePlivoTrunk($trunkId, &$debugLog = null) {

    $response = plivoApiRequest('DELETE', 'Trunk/' . $trunkId . '/', null, $debugLog, true);

    return $response;

}

// Delete all inbound trunks that contain this phone number

function deleteInboundTrunksForNumber($serverUrl, $token, $phoneNumber, &$debugLog = null) {

    $endpointList = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/ListSIPInboundTrunk';

    $listBody     = (object)[];

    $listRes      = httpPostJson($endpointList, $token, $listBody, $debugLog);

    // Normalise trunks array from various possible shapes

    $trunks = [];

    if (isset($listRes['trunks']) && is_array($listRes['trunks'])) {

        $trunks = $listRes['trunks'];

    } elseif (is_array($listRes)) {

        $trunks = $listRes;

    }

    foreach ($trunks as $trunk) {

        $numbers = [];

        if (isset($trunk['numbers']) && is_array($trunk['numbers'])) {

            $numbers = array_merge($numbers, $trunk['numbers']);

        }

        if (isset($trunk['numbers']['set']) && is_array($trunk['numbers']['set'])) {

            $numbers = array_merge($numbers, $trunk['numbers']['set']);

        }

        if (!in_array($phoneNumber, $numbers, true)) {

            continue;

        }

        $trunkId = $trunk['sip_trunk_id'] ?? ($trunk['id'] ?? ($trunk['sid'] ?? null));

        if (!$trunkId) {

            if ($debugLog !== null) {

                $debugLog[] = [

                    'step'    => 'delete_trunk_skip_no_id',

                    'phone'   => $phoneNumber,

                    'trunk'   => $trunk,

                ];

            }

            continue;

        }

        $endpointDel = rtrim($serverUrl, '/') . '/twirp/livekit.SIP/DeleteSIPTrunk';

        $delBody     = ['sip_trunk_id' => $trunkId];

        $delRes      = httpPostJson($endpointDel, $token, $delBody, $debugLog);

        if ($debugLog !== null) {

            $debugLog[] = [

                'step'     => 'delete_trunk_attempted',

                'phone'    => $phoneNumber,

                'trunk_id' => $trunkId,

                'result'   => $delRes,

            ];

        }

    }

}
