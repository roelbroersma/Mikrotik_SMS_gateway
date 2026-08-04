<?php

/*
 ########################################################################
 # FILE:		SENDSMS.PHP												#
 # AUTHOR:		ROEL BROERSMA											#
 # DESCRIPTION:	THIS FILE CAN BE RUN FROM A PHP HOST OR FROM DOCKER		#
 #				AND WILL WORK AS A REST API TO SEND A SMS TO A			#
 #				MIKROTIK DEVICE. IF IT COULD NOT BE SENT IT WILL		#
 #				QUEUE THE SMS TO A FILE WHICH WILL THEN LATER BE		#
 #				BE SENT BY A SCHEDULER SCRIPT ON THE MIKROTIK DEVICE	#
 ########################################################################
 */

/* Configuration loaded from environment variables with safe defaults */
$sms_gateway_url 		= getenv('SMS_GATEWAY_URL')			?: 'http://localhost';					// THE LOCATION OF THE MIKROTIK DEVICE (E.G. WAP AC LTE KIT)
$sms_gateway_user 		= getenv('SMS_GATEWAY_USER')		?: 'api_user_of_mikrotik';				// API USERNAME (TIP: CREATE A NEW MIKROTIK API USER)
$sms_gateway_pass 		= getenv('SMS_GATEWAY_PASS')		?: 'api_password_of_mikrotik';			// API PASSWORD (TIP: CREATE A NEW MIKROTIK API USER)
$sms_queue_file   		= getenv('SMS_QUEUE_FILE')			?: 'sms_queue.txt';						// THE FILE ON THE MIKROTIK ROUTER TO WHICH SMS ARE SAVED WHEN THEY COULD NOT BE SENT (E.G. LTE IS DOWN)
$allowed_ip_ranges_raw	= getenv('ALLOWED_IP_RANGES')		?: '192.168.0.0/21,192.168.10.0/24';	// ALLOW ONLY FROM THESE IPV4 CIDR RANGES (SEPARATE MULTIPLE RANGES BY A COMMA)
$rate_limits_raw		= getenv('RATE_LIMITS')			?: '*:10/600';						// PER-IP LIMITS: IP_OR_CIDR:MAX/SECONDS, COMMA SEPARATED; USE "OFF" TO DISABLE
$only_dutch				= strtolower(getenv('ONLY_DUTCH')	?: 'true') === 'true';					// SET TO TRUE TO ONLY SEND TO DUTCH +316xxxxxxx NUMBERS
$log_to_file			= strtolower(getenv('LOG_TO_FILE')	?: 'true') === 'true';					// TRUE WRITES TO A FILE; FALSE WRITES TO STDERR (VISIBLE IN DOCKER LOGS)
$sms_log_file			= getenv('SMS_LOG_FILE')			?: 'sms_logfile.log';					// FILE USED WHEN LOG_TO_FILE IS TRUE


/* Accept input from form-data first, otherwise fall back to JSON body */
if ( !empty($_POST['phone']) || !empty($_POST['text']) ) {
	$phone	= trim($_POST['phone'] ?? '');
	$text	= trim($_POST['text'] ?? '');
} else {
	$data	= json_decode(file_get_contents('php://input'), true);
	$phone	= trim($data['phone'] ?? '');
	$text	= trim($data['text'] ?? '');
}

/* Queue records are line-based, so tabs and line breaks cannot remain in a message */
$text = preg_replace('/[\r\n\t]+/', ' ', $text);

/* Allow only requests from configured IPv4 CIDR ranges */
$ipaddr = $_SERVER['REMOTE_ADDR'];
$allowed_ip_ranges = array_map('trim', explode(',', $allowed_ip_ranges_raw));
$ip_allowed = false;
foreach ($allowed_ip_ranges as $range) {
	if (ip_in_range($ipaddr, $range)) {
		$ip_allowed = true;
		break;
	}
}
if (!$ip_allowed) {
	http_response_code(403);
	write_to_log("REJECTED: IP address is not allowed");
	echo "NOT ALLOWED! THIS REQUEST IS LOGGED.";
	return false;
}

/* Limit incoming HTTP requests per connecting IP. Scheduler retries do not pass here. */
try {
	$rate_limit = get_rate_limit_for_ip($ipaddr, $rate_limits_raw);
	if ($rate_limit !== false) {
		$rate_status = consume_rate_limit($ipaddr, $rate_limit['max'], $rate_limit['seconds']);
		if (isset($rate_status['error'])) {
			http_response_code(503);
			write_to_log("RATE LIMIT ERROR: " . $rate_status['error']);
			echo "RATE LIMITER UNAVAILABLE.";
			return false;
		}

		header("X-RateLimit-Limit: " . $rate_limit['max']);
		header("X-RateLimit-Remaining: " . $rate_status['remaining']);
		header("X-RateLimit-Reset: " . $rate_status['reset']);

		if (!$rate_status['allowed']) {
			http_response_code(429);
			header("Retry-After: " . $rate_status['retry_after']);
			write_to_log("RATE LIMITED: max " . $rate_limit['max'] . " requests per " . $rate_limit['seconds'] . " seconds");
			echo "RATE LIMIT EXCEEDED. TRY AGAIN IN " . $rate_status['retry_after'] . " SECONDS.";
			return false;
		}
	}
} catch (InvalidArgumentException $exception) {
	http_response_code(500);
	write_to_log("RATE LIMIT CONFIG ERROR: " . $exception->getMessage());
	echo "INVALID RATE_LIMITS CONFIGURATION.";
	return false;
}

/* Normalize phone input */
$phone = preg_replace('/[\s\-]+/', '', $phone);
if (preg_match('/^316\d{8}$/', $phone)) {
	$phone = '+' . $phone;
}
if (preg_match('/^06\d{8}$/', $phone)) {
	$phone = '+31' . substr($phone, 1);
}

if ( empty($phone) || empty($text) ) {
	echo "NO VALID DATA SEND.";
	return false;
}

/* Validate phone format and optionally restrict to Dutch mobile numbers */
if ( !(preg_match("/^(\+[0-9]{8,14}|0[1-9][0-9]{8}|00[0-9]{7,14})$/", $phone)) ) {
	echo "NUMBER NOT SEND IN INTERNATIONAL FORMAT, i.e.: +31612345678";
	return false;
}

if ( $only_dutch && !(preg_match("/^\+316[0-9]{8}$/", $phone)) ) {
	echo "ONLY DUTCH INTERNATIONAL MOBILE NUMBERS ARE ALLOWED, i.e.: +31612345678";
	return false;
}

if ( strlen($text)>160 ) {
	echo "TEXT TOO LONG (".strlen($text)."), MAX 160 CHARACTERS ALLOWED.";
	return false;
}


	
$result = FALSE;

/* Try to send the SMS directly through the MikroTik REST API */
$url      = $sms_gateway_url . '/rest/tool/sms/send';
$data     = array('port' => 'lte1', 'phone-number' => $phone, 'message' => $text);
$json_data= json_encode($data);
$options = array(
		'http' => array(
				'method'  => 'POST',
				'header'  => "Authorization: Basic " . base64_encode("$sms_gateway_user:$sms_gateway_pass") . "\r\n".
					 "Content-type: application/json\r\n".
					 "Content-Length: ". strlen($json_data) . "\r\n",
				'content' => $json_data
				),
		'ssl' => array(
				'verify_peer'      => false,
				'verify_peer_name' => false,
				),
		);
$context  = stream_context_create($options);
$result   = file_get_contents($url, false, $context);

/* If direct sending fails, append the message to the router-side queue file */
if ($result === FALSE) {
	// THERE IS SOME ERROR SENDING THE SMS, SAVE THE SMS ON THE SMS-GATEWAY SO IT WILL BE SEND AT A LATER TIME
	// GET CURRENT SMS_QUEUE CONTENTS
	$url     = $sms_gateway_url . '/rest/file/' . $sms_queue_file;
	$options = array(
			'http' => array(
					'header'  => "Authorization: Basic " . base64_encode("$sms_gateway_user:$sms_gateway_pass") . "\r\n".
					 "Content-type: application/json\r\n",
					'method'  => 'GET',
					),
			'ssl' => array(
					'verify_peer'      => false,
					'verify_peer_name' => false,
					),
			);
	$context          = stream_context_create($options);
	$sms_queue_result = file_get_contents($url, false, $context);

	$json_sms_queue   = json_decode($sms_queue_result,true);
    $sms_queue        = $json_sms_queue['contents'] ?? '';

    // CREATE FILE IF IT DOESNT EXISTS
    if ($sms_queue_result === false || !is_array($json_sms_queue)) {
        $create_url = $sms_gateway_url . '/rest/file/add';
        $create_data = array('name' => $sms_queue_file, 'contents' => '');
        $create_json = json_encode($create_data);
    
        $create_options = array(
            'http' => array(
                'header' => "Authorization: Basic " . base64_encode("$sms_gateway_user:$sms_gateway_pass") . "\r\n" .
                            "Content-type: application/json\r\n" .
                            "Content-Length: " . strlen($create_json) . "\r\n",
                'method' => 'POST',
                'content' => $create_json
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
            ),
        );
    
        file_get_contents($create_url, false, stream_context_create($create_options));
        $sms_queue = '';
    }
	/* Normalize old CRLF/CR queues and always store one record per LF line */
	$sms_queue = str_replace(array("\r\n", "\r"), "\n", $sms_queue);
	$sms_queue = trim($sms_queue, "\n");
	if ($sms_queue !== '')
		$sms_queue = $sms_queue."\n";

	// SET NEW SMS_QUEUE
	$url      = $sms_gateway_url . '/rest/file/set';
	$data     = array('.id' => $sms_queue_file, 'contents' => $sms_queue."$phone\t$text");
	$json_data= json_encode($data);
	$options  = array(
			'http' => array(
					'header'  => "Authorization: Basic " . base64_encode("$sms_gateway_user:$sms_gateway_pass") . "\r\n".
						 "Content-type: application/json\r\n".
						 "Content-Length: ". strlen($json_data) . "\r\n",
					'method'  => 'POST',
					'content' => $json_data
					),
			'ssl' => array(
					'verify_peer'      => false,
					'verify_peer_name' => false,
					),
			);
	$context  = stream_context_create($options);
	$result = file_get_contents($url, false, $context);
	if ($result === FALSE) {
		http_response_code(502);
		echo "SMS COULD NOT BE QUEUED.";
		write_to_log ("QUEUE FAILED: " .$phone." - ".$text);
		return false;
	}

	echo "SMS SUCCESSFULLY QUEUED.";
	write_to_log ("QUEUED: " .$phone." - ".$text);

	return false;
}

//echo $config;
echo "SMS SUCCESSFULLY SENT.";
write_to_log ($phone." - ".$text);




/** CHECK IF IP IS IN RANGE **/
/** SOURCE: https://gist.github.com/tott/7684443 **/
function ip_in_range( $ip, $range ) {
	if ( strpos( $range, '/' ) == false ) {
		$range .= '/32';
	}
	// $range is in IP/CIDR format eg 127.0.0.1/24
	list( $range, $netmask ) = explode( '/', $range, 2 );
	$range_decimal = ip2long( $range );
	$ip_decimal = ip2long( $ip );
	$wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
	$netmask_decimal = ~ $wildcard_decimal;
	return ( ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal ) );
}

/**
 * GET THE RATE LIMIT FOR AN IP ADDRESS.
 *
 * FORMAT EXAMPLES:
 *   192.168.0.175:10/600,*:5/600
 *   192.168.0.0/24:20/600
 *
 * AN EXACT IP OR CIDR RULE TAKES PRECEDENCE OVER THE WILDCARD RULE.
 */
function get_rate_limit_for_ip($ip, $rules_raw) {
	if ($rules_raw === '' || in_array(strtolower(trim($rules_raw)), array('off', 'none', 'false', '0'), true)) {
		return false;
	}

	$default_limit = false;
	foreach (explode(',', $rules_raw) as $rule) {
		$rule = trim($rule);
		$separator = strrpos($rule, ':');
		if ($separator === false) {
			throw new InvalidArgumentException("Invalid rule: $rule");
		}

		$selector = trim(substr($rule, 0, $separator));
		$limit = trim(substr($rule, $separator + 1));
		if (!preg_match('/^([1-9][0-9]*)\/([1-9][0-9]*)$/', $limit, $matches)) {
			throw new InvalidArgumentException("Invalid limit: $limit");
		}

		$parsed_limit = array('max' => (int)$matches[1], 'seconds' => (int)$matches[2]);
		if ($selector === '*') {
			$default_limit = $parsed_limit;
			continue;
		}

		if (ip_in_range($ip, $selector)) {
			return $parsed_limit;
		}
	}

	return $default_limit;
}

/**
 * CONSUME ONE REQUEST FROM A ROLLING-WINDOW RATE LIMIT.
 *
 * APCU KEEPS ONLY A SMALL ARRAY OF TIMESTAMPS IN RAM. THE SHORT LOCK MAKES
 * THE UPDATE ATOMIC WHEN PHP HAS MULTIPLE WORKERS. ALL DATA EXPIRES
 * AUTOMATICALLY AND IS RESET WHEN THE CONTAINER RESTARTS.
 */
function consume_rate_limit($ip, $maximum, $seconds) {
	if (!function_exists('apcu_enabled') || !apcu_enabled()) {
		return array('error' => 'APCu is not enabled');
	}

	$key = 'sms_rate_' . hash('sha256', "$ip|$maximum|$seconds");
	$lock_key = $key . '_lock';
	$lock_timeout = microtime(true) + 0.25;

	while (!apcu_add($lock_key, 1, 1)) {
		if (microtime(true) >= $lock_timeout) {
			return array('error' => 'Could not acquire APCu lock');
		}
		usleep(1000);
	}

	$now = microtime(true);
	$timestamps = apcu_fetch($key, $found);
	if (!$found || !is_array($timestamps)) {
		$timestamps = array();
	}

	/* Remove requests that are older than the configured rolling window */
	$oldest_allowed = $now - $seconds;
	$timestamps = array_values(array_filter($timestamps, function ($timestamp) use ($oldest_allowed) {
		return is_numeric($timestamp) && $timestamp > $oldest_allowed;
	}));

	if (count($timestamps) >= $maximum) {
		$reset = (int)ceil($timestamps[0] + $seconds);
		apcu_store($key, $timestamps, $seconds + 1);
		apcu_delete($lock_key);
		return array(
			'allowed' => false,
			'remaining' => 0,
			'retry_after' => max(1, $reset - time()),
			'reset' => $reset
		);
	}

	$timestamps[] = $now;
	apcu_store($key, $timestamps, $seconds + 1);
	apcu_delete($lock_key);

	return array(
		'allowed' => true,
		'remaining' => max(0, $maximum - count($timestamps)),
		'retry_after' => 0,
		'reset' => (int)ceil($timestamps[0] + $seconds)
	);
}

/* Write a log line either to file or stderr, depending on configuration */
function write_to_log($text_to_log) {
	global $log_to_file, $sms_log_file;

	$log_line = date(DATE_ATOM) . " - " . $_SERVER['REMOTE_ADDR'] . " - " . $text_to_log;

	if ($log_to_file) {
		file_put_contents($sms_log_file, $log_line.PHP_EOL, FILE_APPEND);
	} else {
		/* error_log writes to Docker stderr instead of polluting the HTTP response */
		error_log($log_line);
	}
}

?>
