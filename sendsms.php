<?php

/*
 ########################################################################
 # FILE:		SENDSMS.PHP												#
 # AUTHOR:		ROEL BROERSMA											#
 # DESCRIPTION:	THIS FILE CAN BE RUN FROM A PHP HOST OR FROM DOCKER		#
 #				AND WILL WORK AS A REST API TO SEND A SMS TO A			#
 #				MIKROTIK DEVICE. IF IT COULD NOT BE SEND IT WILL		#
 #				QUEUE THE SMS TO A FILE WHICH WILL THEN LATER BE		#
 #				SEND FROM A SCHEDULER SCRIPT ON THE MIKROTIK DEVICE		#
 ########################################################################
 */

$sms_gateway_url 		= getenv('SMS_GATEWAY_URL')			?: 'http://localhost';					// THE LOCATION OF THE MIKROTIK DEVICE (E.G. WAP AC LTE KIT)
$sms_gateway_user 		= getenv('SMS_GATEWAY_USER')		?: 'api_user_of_mikrotik';				// API USERNAME (TIP: CREATE A NEW MIKROTIK API USER)
$sms_gateway_pass 		= getenv('SMS_GATEWAY_PASS')		?: 'api_password_of_mikrotik';			// API PASSWORD (TIP: CREATE A NEW MIKROTIK API USER)
$sms_queue_file   		= getenv('SMS_QUEUE_FILE')			?: 'sms_queue.txt';						// THE FILE ON THE MIKROTIK ROUTER TO WHICH SMS ARE SAVED WHEN THEY COULD NOT BE SEND (EG. LTE IS DOWN)
$allowed_ip_ranges_raw	= getenv('ALLOWED_IP_RANGES')		?: '192.168.0.0/21,192.168.10.0/24';	// ALLOW ONLY FROM THESE IPV4 CIDR RANGES (SEPARATE MULTIPLE RANGES BY A COMMA)
$only_dutch				= strtolower(getenv('ONLY_DUTCH')	?: 'true') === 'true';					// SET TO TRUE TO ONLY SEND TO DUTCH +316xxxxxxx NUMBERS
$log_to_file			= strtolower(getenv('LOG_TO_FILE')	?: 'true') === 'true';					// SET TO TRUE TO LOG TO FILE (IF ON DOCKER, IT WILL NOT LOG TO FILE BUT TO STDOUT)
$sms_log_file			= getenv('SMS_LOG_FILE')			?: 'sms_logfile.log';					// IF NOT ON DOCKER, AND THE ABOVE LINE IS TRUE, LOG TO THIS FILE



if ( !empty($_POST['phone']) || !empty($_POST['text']) ) {
	$phone	= trim($_POST['phone']);
	$text	= trim($_POST['text']);
} else {
	$data	= json_decode(file_get_contents('php://input'), true);
	$phone	= trim($data['phone']);
	$text	= trim($data['text']);
}

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
	echo "NOT ALLOWED! THIS REQUEST IS LOGGED.";
	return false;
}

if ( empty($phone) || empty($text) ) {
	echo "NO VALID DATA SEND.";
	return false;
}

if ( !(preg_match("/^((\+)[0-9]{8,14})|([0]{1,1}[1-9]{1,1}[0-9]{8,8})|([0]{2,2}[0-9]{7,14})/i", $phone)) ) {
	echo "NUMBER NOT SEND IN INTERNATIONAL FORMAT, i.e.: +3161234567";
	return false;
}

if ( $only_dutch && !(preg_match("/^((\+)(316)[0-9]{7,7})/i", $phone)) ) {
	echo "ONLY DUTCH INTERNATIONAL MOBILE NUMBERS ARE ALLOWED, i.e.: +3161234567";
	return false;
}

if ( strlen($text)>160 ) {
	echo "TEXT TOO LONG (".strlen($text)."), MAX 160 CHARACTERS ALLOWED.";
	return false;
}


	
$result = FALSE;


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
	$sms_queue        = $json_sms_queue['contents'];
	if (strlen($sms_queue)>10)
		$sms_queue = $sms_queue."\r\n";

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


function write_to_log($text_to_log) {
	global $log_to_file, $sms_log_file;

	$log_line = date(DATE_ATOM) . " - " . $_SERVER['REMOTE_ADDR'] . " - " . $text_to_log;

	if ($log_to_file) {
		file_put_contents($sms_log_file, $log_line.PHP_EOL, FILE_APPEND);
	} else {
		echo $log_line . PHP_EOL;
	}
}

?>
