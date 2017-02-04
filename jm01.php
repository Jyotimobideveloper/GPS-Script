<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logerrors.inc.php';
$tipoLog = "arquivo";
$fh = null;
$remip = null;
$remport = null;
$imei = '';
function abrirArquivoLog($imeiLog) {
	GLOBAL $fh;
    $fn = "Jm01_" . trim($imeiLog) . ".log";
	$fn = trim($fn);
	$fh = fopen($fn, 'a') or die ("Não consegue criar arquivo");
	$tempstr = "Inicio do Log".chr(13).chr(10); 
	fwrite($fh, $tempstr);	
}
function fecharArquivoLog() {
	GLOBAL $fh;
	if ($fh != null)
		fclose($fh);
}
function printLog( $fh, $mensagem ) {
	GLOBAL $tipoLog;
	GLOBAL $fh;
	
    if ($tipoLog == "arquivo") {
		if ($fh != null)
			fwrite($fh, $mensagem.chr(13).chr(10));
    } else {
		echo $mensagem."<br />";
    }
}
$ip = '000.000.000.000';
$port = '0000';
$command_path = "";
$__server_listening = true;
error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
declare(ticks = 1);
become_daemon();
pcntl_signal(SIGTERM, 'sig_handler');
pcntl_signal(SIGINT, 'sig_handler');
pcntl_signal(SIGCHLD, 'sig_handler');
server_loop($ip, $port);
function change_identity( $uid, $gid ) {
    if( !posix_setgid( $gid ) ) {
        print "Não é possível definir o id para " . $gid . "!\n";
        exit;
    }
    if( !posix_setuid( $uid ) ) {
        print "Não é possível definir o id para " . $uid . "!\n";
        exit;
    }
}
function server_loop($address, $port) {
    GLOBAL $fh;
    GLOBAL $__server_listening;
	printLog($fh, "server_looping...");
    if(($sock = socket_create(AF_INET, SOCK_STREAM, 0)) < 0) {
		printLog($fh, "Falhou ao criar o soquete: ".socket_strerror($sock));
        exit();
    }
	if(($ret = socket_bind($sock, $address, $port)) < 0) {
		printLog($fh, "Falha ao ligar o socket: ".socket_strerror($ret));
		exit();
	}
	if( ( $ret = socket_listen( $sock, 0 ) ) < 0 ) {
		printLog($fh, "Falha ao ouvir o socket: ".socket_strerror($ret));
		exit();
	}
	socket_set_nonblock($sock);
	printLog($fh, "Esperando que os clientes se conectem...");
	while ($__server_listening) {
		$connection = @socket_accept($sock);
		if ($connection === false) {
			usleep(100);
		} elseif ($connection > 0) {
			handle_client($sock, $connection);
		} else {
			printLog($fh, "error: ".socket_strerror($connection));
			die;
		}
	}
}
function sig_handler($sig) {
	switch($sig) {
		case SIGTERM:
		case SIGINT:
			break;

		case SIGCHLD:
			pcntl_waitpid(-1, $status);
		break;
	}
}
$firstInteraction = false;
function handle_client($ssock, $csock) {
	GLOBAL $__server_listening;
	GLOBAL $fh;
	GLOBAL $firstInteraction;
	GLOBAL $remip;
	GLOBAL $remport;
	$pid = pcntl_fork();
	if ($pid == -1) {
		die;
	} elseif ($pid == 0) {
		$__server_listening = false;
		socket_getpeername($csock, $remip, $remport);
		$firstInteraction = true;
		socket_close($ssock);
		interact($csock);
		socket_close($csock);
		printLog($fh, date("d-m-Y H:i:s") . " Conexão com $remip:$remport fechada");
		fecharArquivoLog();
	} else {
		socket_close($csock);
	}
}
function interact($socket) {
	GLOBAL $fh;
	GLOBAL $command_path;
	GLOBAL $firstInteraction;
	GLOBAL $remip;
	GLOBAL $remport;	
	GLOBAL $imei;	

	$loopcount = 0;
	$conn_imei = "";
	$rec = "";
	$tipoComando = "banco"; //"arquivo";
	$isGIMEI = false;
	$isGPRMC = false;
	$send_cmd = "";
	$last_status = "";
	while (@socket_recv($socket, $rec, 2048, 0x40) !== 0) {
	    if ($conn_imei != "") {
			if ($tipoComando == "arquivo" and file_exists("$command_path/$conn_imei")) {
				$send_cmd = file_get_contents("$command_path/$conn_imei");
				
				$sendcmd = trataCommand($send_cmd, $conn_imei);
				socket_send($socket, $sendcmd, strlen($sendcmd), 0);
				
				unlink("$command_path/$conn_imei");
				printLog($fh, "Arquivo de comandos apagado: " . $sendcmd . " imei: " . $conn_imei);
			} else {
		if ($tipoComando == "banco" and file_exists("$command_path/$conn_imei")) {
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        }
        mysqli_set_charset($link, 'utf8');
					$res = mysqli_query($link, "SELECT c.command FROM command c WHERE c.imei = '$conn_imei' ORDER BY date DESC LIMIT 1");
					$sendcmd = '';
					while($data = mysqli_fetch_assoc($res)) {
						$sendcmd = trataCommand($data['command'], $conn_imei);
					}
					
					socket_send($socket, $sendcmd, strlen($sendcmd), 0);
					mysqli_close($link);
					unlink("$command_path/$conn_imei");
					printLog($fh, "Comandos do arquivo apagado: " . $sendcmd . " imei: " . $conn_imei);
				} else {
					if ($firstInteraction == true) {
						sleep (1);
						$firstInteraction = false;
					}
				}
			}
		}
		if(file_exists("$command_path/$conn_imei")){
			$send_cmd = file_get_contents("$command_path/$conn_imei");
			if($send_cmd == 'shutdown'){
				unlink("$command_path/$conn_imei");
				socket_shutdown($socket, 2);
			}
		}
		sleep (1);
		$loopcount++;
		if ($loopcount > 120) return;
		if ($rec != "") {
			$isGt06 = false;
			$tempString = $rec."";
			$retTracker = hex_dump($rec."");
			$arCommands = explode(' ',trim($retTracker));
			if(count($arCommands) > 0){
				if($arCommands[0].$arCommands[1] == '7878'){
					$isGt06 = true;
				}
			}
			
			if($isGt06){
				$arCommands = explode(' ',$retTracker);
				$tmpArray = array_count_values($arCommands);
				
				$count = $tmpArray[78];
				$count =  $count / 2;
				
				$tmpArCommand = array();
				if($count >= 1){
					$ar = array();
					for($i=0;$i<count($arCommands);$i++){
						if(strtoupper(trim($arCommands[$i]))=="78" && isset($arCommands[$i+1]) && strtoupper(trim($arCommands[$i+1])) == "78"){
							$ar = array();
							if(strlen($arCommands[$i]) == 4){
								$ar[] = substr($arCommands[$i],0,2);
								$ar[] = substr($arCommands[$i],2,2);
							} else {
								$ar[] = $arCommands[$i];
							}
						} elseif(isset($arCommands[$i+1]) && strtoupper(trim($arCommands[$i+1]))=="78" && strtoupper(trim($arCommands[$i]))!="78" && isset($arCommands[$i+2]) && strtoupper(trim($arCommands[$i+2]))=="78"){
							if(strlen($arCommands[$i]) == 4){
								$ar[] = substr($arCommands[$i],0,2);
								$ar[] = substr($arCommands[$i],2,2);
							} else {
								$ar[] = $arCommands[$i];
							}
							$tmpArCommand[] = $ar;
						} elseif($i == count($arCommands)-1){
							if(strlen($arCommands[$i]) == 4){
								$ar[] = substr($arCommands[$i],0,2);
								$ar[] = substr($arCommands[$i],2,2);
							} else {
								$ar[] = $arCommands[$i];
							}
							$tmpArCommand[] = $ar;
						} else {
							if(strlen($arCommands[$i]) == 4){
								$ar[] = substr($arCommands[$i],0,2);
								$ar[] = substr($arCommands[$i],2,2);
							} else {
								$ar[] = $arCommands[$i];
							}
						}
					}
				}
				for($i=0;$i<count($tmpArCommand);$i++) {
					$arCommands = $tmpArCommand[$i];
					$sizeData = $arCommands[2];
					
					$protocolNumber = strtoupper(trim($arCommands[3]));
					
					if($protocolNumber == '01'){
						$imei = '';
						
						for($i=4; $i<12; $i++){
							$imei = $imei.$arCommands[$i];
						}
						$imei = substr($imei,1,15);
						$conn_imei = $imei;
						
						abrirArquivoLog($imei);
						
						$sendCommands = array();
						
						$send_cmd = '78 78 05 01 '.strtoupper($arCommands[12]).' '.strtoupper($arCommands[13]);
						
						atualizarBemSerial($conn_imei, strtoupper($arCommands[12]).' '.strtoupper($arCommands[13]));
						
						$newString = '';
						$newString = chr(0x05).chr(0x01).$rec[12].$rec[13];
						$crc16 = GetCrc16($newString,strlen($newString));
						$crc16h = floor($crc16/256);
						$crc16l = $crc16 - $crc16h*256;
						$crc = dechex($crc16h).' '.dechex($crc16l);
						$send_cmd = $send_cmd. ' ' . $crc . ' 0D 0A';
						$sendCommands = explode(' ', $send_cmd);
						
						printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Got: ".implode(" ",$arCommands));
						printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Sent: $send_cmd Length: ".strlen($send_cmd));
						
						$send_cmd = '';
						for($i=0; $i<count($sendCommands); $i++){
							$send_cmd .= chr(hexdec(trim($sendCommands[$i])));
						}
						socket_send($socket, $send_cmd, strlen($send_cmd), 0);
					    } else if ($protocolNumber == '12') {
						printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Got: ".implode(" ",$arCommands));
						$dataPosition = hexdec($arCommands[4]).'-'.hexdec($arCommands[5]).'-'.hexdec($arCommands[6]).' '.hexdec($arCommands[7]).':'.hexdec($arCommands[8]).':'.hexdec($arCommands[9]);
						$gpsQuantity = $arCommands[10];
						$lengthGps = hexdec(substr($gpsQuantity,0,1));
						$satellitesGps = hexdec(substr($gpsQuantity,1,1));
						$latitudeHemisphere = '';
						$longitudeHemisphere = '';
						$speed = hexdec($arCommands[19]);
						
						if(isset($arCommands[20]) && isset($arCommands[21])){
							$course = decbin(hexdec($arCommands[20]));
							while(strlen($course) < 8) $course = '0'.$course;
							
							$status = decbin(hexdec($arCommands[21]));
							while(strlen($status) < 8) $status = '0'.$status;
							$courseStatus = $course.$status;
							
							$gpsRealTime = substr($courseStatus, 2,1) == '0' ? 'F':'D';
							$gpsPosition = substr($courseStatus, 3,1) == '0' ? 'F':'L';
							$gpsPosition == 'F' ? 'S' : 'N';							
							$latitudeHemisphere = substr($courseStatus, 5,1) == '0' ? 'S' : 'N';
							$longitudeHemisphere = substr($courseStatus, 4,1) == '0' ? 'E' : 'W';
						}
						$latHex = hexdec($arCommands[11].$arCommands[12].$arCommands[13].$arCommands[14]);
						$lonHex = hexdec($arCommands[15].$arCommands[16].$arCommands[17].$arCommands[18]);
						
						$latitudeDecimalDegrees = ($latHex*90)/162000000;
						$longitudeDecimalDegrees = ($lonHex*180)/324000000;
						
						$latitudeHemisphere == 'S' && $latitudeDecimalDegrees = $latitudeDecimalDegrees*-1;
						$longitudeHemisphere == 'W' && $longitudeDecimalDegrees = $longitudeDecimalDegrees*-1;
						if(isset($arCommands[30]) && isset($arCommands[30])){
							atualizarBemSerial($conn_imei, strtoupper($arCommands[30]).' '.strtoupper($arCommands[31]));
						} else {
							echo 'Imei: '.$imei.' Got:'.$retTracker;
						}
						$dados = array($gpsPosition, 
										$latitudeDecimalDegrees, 
										$longitudeDecimalDegrees, 
										$latitudeHemisphere, 
										$longitudeHemisphere, 
										$speed, 
										$imei,
										$dataPosition,
										'tracker',
										'',
										'S',
										$gpsRealTime);
						
						tratarDados($dados);
					   } else if ($protocolNumber == '13') {
						$terminalInformation = decbin(hexdec($arCommands[4]));
						while(strlen($terminalInformation) < 8) $terminalInformation = '0'.$terminalInformation; //00101110
						$gasOil = substr($terminalInformation,0,1) == '0' ? 'S' : 'N';
						$gpsTrack = substr($terminalInformation,1,1) == '1' ? 'S' : 'N';
						$alarm = '';
						switch(substr($terminalInformation,2,3)){
							case '100': $alarm = 'help me'; break;
							case '011': $alarm = 'low battery'; break;
							case '010': $alarm = 'dt'; break;
							case '001': $alarm = 'move'; break;
							case '000': $alarm = 'tracker'; break;
						}
						
						$ativo = substr($terminalInformation,7,1) == '1' ? 'S' : 'S';
						$charge = substr($terminalInformation,5,1) == '1' ? 'S' : 'N';
						$acc = substr($terminalInformation,6,1) == '1' ? 'S' : 'N';
						$voltageLevel = hexdec($arCommands[5]);
						$gsmSignal = hexdec($arCommands[6]);
						$alarmLanguage = hexdec($arCommands[7]);
						
						switch($alarmLanguage){
							case 0: $alarm = 'tracker'; break;
							case 1: $alarm = 'help me'; break;
							case 2: $alarm = 'dt'; break;
							case 3: $alarm = 'move'; break;
							case 4: $alarm = 'stockade'; break;
							case 5: $alarm = 'stockade'; break;
						}
					
						$sendCommands = array();
						if(strlen($arCommands[9]) == 4 && count($arCommands) == 10){
							$arCommands[9] = substr($terminalInformation,0,2);
							$arCommands[] = substr($terminalInformation,2,2);
						}
						
						$send_cmd = '78 78 05 13 '.strtoupper($arCommands[9]).' '.strtoupper($arCommands[10]);
						
						$newString = '';
						$newString = chr(0x05).chr(0x13).$rec[9].$rec[10];
						$crc16 = GetCrc16($newString,strlen($newString));
						$crc16h = floor($crc16/256);
						$crc16l = $crc16 - $crc16h*256;
						$crc = dechex($crc16h).' '.dechex($crc16l);
						$send_cmd = $send_cmd. ' ' . $crc . ' 0D 0A';
						$sendCommands = explode(' ', $send_cmd);
						atualizarBemSerial($conn_imei, strtoupper($arCommands[9]).' '.strtoupper($arCommands[10]));
						printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Got: ".implode(" ",$arCommands));
						printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Sent: $send_cmd Length: ".strlen($send_cmd));
						$send_cmd = '';
						for($i=0; $i<count($sendCommands); $i++){
							$send_cmd .= chr(hexdec(trim($sendCommands[$i])));
						}
						socket_send($socket, $send_cmd, strlen($send_cmd), 0);
						
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        mysqli_set_charset($link, 'utf8');
							$res = mysqli_query($link, "SELECT * FROM loc_atual WHERE imei = '$imei'");
							if($res !== false){
								$data = mysqli_fetch_assoc($res);
								mysqli_close($link);
								$dados = array($gpsTrack, 
											$data['latitudeDecimalDegrees'], 
											$data['longitudeDecimalDegrees'], 
											$data['latitudeHemisphere'], 
											$data['longitudeHemisphere'], 
											0, 
											$imei,
											date('Y-m-d'),
											$alarm,
											$acc,
											$ativo);
							
								tratarDados($dados);
							}
						}
					   } else if ($protocolNumber == '15') {
						printLog($fh, date("d-m-y h:i:sa") . " Got: $retTracker");
						$msg = '';
						for($i=9; $i<count($arCommands)-8; $i++){
							$msg .= chr(hexdec($arCommands[$i]));
						}
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        mysqli_set_charset($link, 'utf8');
							
							$alerta = '';
							if(strpos($msg, 'Already') > -1){
								$alerta = 'Bloqueio já efetuado!';
							}
							
							if(strpos($msg, 'DYD=Suc') > -1){
								$alerta = 'Bloqueio efetuado!';
							}
							
							if(strpos($msg, 'HFYD=Su') > -1){
								$alerta = 'Desbloqueio efetuado!';
							}
							
							
							mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$conn_imei', '$alerta')");
							mysqli_close($link);
						}
				    	} else if ($protocolNumber == '16') {
						printLog($fh, date("d-m-y h:i:sa") . " Got: ".implode(" ",$arCommands));
						$dataPosition = hexdec($arCommands[4]).'-'.hexdec($arCommands[5]).'-'.hexdec($arCommands[6]).' '.hexdec($arCommands[7]).':'.hexdec($arCommands[8]).':'.hexdec($arCommands[9]);
						$gpsQuantity = $arCommands[10];
						$lengthGps = hexdec(substr($gpsQuantity,0,1));
						$satellitesGps = hexdec(substr($gpsQuantity,1,1));
						$latitudeHemisphere = '';
						$longitudeHemisphere = '';
						$speed = hexdec($arCommands[19]);
						$course = decbin(hexdec($arCommands[20]));
	
						while(strlen($course) < 8) $course = '0'.$course;
						$status = decbin(hexdec($arCommands[21]));
						while(strlen($status) < 8) $status = '0'.$status;
						$courseStatus = $course.$status;
						
						$gpsRealTime = substr($courseStatus, 2,1);
						$gpsPosition = substr($courseStatus, 3,1) == '0' ? 'F':'L';
						$gpsPosition = 'S';
						$latitudeHemisphere = substr($courseStatus, 5,1) == '0' ? 'S' : 'N';
						$longitudeHemisphere = substr($courseStatus, 4,1) == '0' ? 'E' : 'W';
						$latHex = hexdec($arCommands[11].$arCommands[12].$arCommands[13].$arCommands[14]);
						$lonHex = hexdec($arCommands[15].$arCommands[16].$arCommands[17].$arCommands[18]);
						$latitudeDecimalDegrees = ($latHex*90)/162000000;
						$longitudeDecimalDegrees = ($lonHex*180)/324000000;
						$latitudeHemisphere == 'S' && $latitudeDecimalDegrees = $latitudeDecimalDegrees*-1;
						$longitudeHemisphere == 'W' && $longitudeDecimalDegrees = $longitudeDecimalDegrees*-1;
						$terminalInformation = decbin(hexdec($arCommands[31]));
						while(strlen($terminalInformation) < 8) $terminalInformation = '0'.$terminalInformation;
						$gasOil = substr($terminalInformation,0,1) == '0' ? 'S' : 'N';
						$gpsTrack = substr($terminalInformation,1,1) == '1' ? 'S' : 'N';
						$alarm = '';
						switch(substr($terminalInformation,2,3)){
							case '100': $alarm = 'help me'; break;
							case '011': $alarm = 'low battery'; break;
							case '010': $alarm = 'dt'; break;
							case '001': $alarm = 'move'; break;
							case '000': $alarm = 'tracker'; break;
						}
						
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        mysqli_set_charset($link, 'utf8');
							if($alarm == "help me")
								mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$conn_imei', 'SOS!')");
							mysqli_close($link);
						}
						$charge = substr($terminalInformation,5,1) == '1' ? 'S' : 'N';
						$acc = substr($terminalInformation,6,1) == '1' ? 'acc on' : 'acc off';
						$defense = substr($terminalInformation,7,1) == '1' ? 'S' : 'N';
						$voltageLevel = hexdec($arCommands[32]);
						$gsmSignal = hexdec($arCommands[33]);
						
						$alarmLanguage = hexdec($arCommands[34]);
						$dados = array($gpsPosition, 
										$latitudeDecimalDegrees, 
										$longitudeDecimalDegrees, 
										$latitudeHemisphere, 
										$longitudeHemisphere, 
										$speed, 
										$imei,
										$dataPosition,
										$alarm, 
										$acc);
						
						tratarDados($dados);
						$send_cmd = '78 78 05 16 '.strtoupper($arCommands[36]).' '.strtoupper($arCommands[37]);
						atualizarBemSerial($conn_imei, strtoupper($arCommands[36]).' '.strtoupper($arCommands[37]));
						$newString = '';
						$newString = chr(0x05).chr(0x16).$rec[36].$rec[37];
						$crc16 = GetCrc16($newString,strlen($newString));
						$crc16h = floor($crc16/256);
						$crc16l = $crc16 - $crc16h*256;
						$crc = dechex($crc16h).' '.dechex($crc16l);
						$send_cmd = $send_cmd. ' ' . $crc . ' 0D 0A';
						$sendCommands = explode(' ', $send_cmd);
						printLog($fh, date("d-m-y h:i:sa") . " Imei: $imei Sent: $send_cmd Length: ".strlen($send_cmd));
						$send_cmd = '';
						for($i=0; $i<count($sendCommands); $i++){
							$send_cmd .= chr(hexdec(trim($sendCommands[$i])));
						}
						socket_send($socket, $send_cmd, strlen($send_cmd), 0);
					    } else if ($protocolNumber == '1A') {
						printLog($fh, date("d-m-y h:i:sa") . " Got: ".implode(" ",$arCommands));
				    	} else if ($protocolNumber == '80') {
						printLog($fh, date("d-m-y h:i:sa") . " Got: ".implode(" ",$arCommands));
					}
				}
			}
		}
		$rec = "";
	}

}

function become_daemon() {
    GLOBAL $fh;
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    } elseif ($pid) {
        exit();
    } else {
        posix_setsid();
        chdir('/');
        umask(0);
        return posix_getpid();
    }
}


/*function checkAlerta($imei, $mensagem){
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        }
        mysqli_set_charset($link, 'utf8');
	$res = mysqli_query($link, "SELECT message, DATE_FORMAT(date, '%d/%m/%Y') AS data FROM message WHERE imei = $imei AND viewed = 'N' ");

	if (mysqli_num_rows($res) > 0) {
		$data = date("d/m/Y");
		while ($alerta = mysqli_fetch_array($res)) {
			if ( $alerta['data'] == $data && (stripos($mensagem, $alerta['message']) !== false) ) return true;
		}
	}
	else return false;
}
*/
function strToHex($string){
    $hex = '';
    for ($i=0; $i<strlen($string); $i++){
        $ord = ord($string[$i]);
        $hexCode = dechex($ord);
        $hex .= substr('0'.$hexCode, -2);
    }
    return strToUpper($hex);
}
function hexToStr($hex){
    $string='';
    for ($i=0; $i < strlen($hex)-1; $i+=2){
        $string .= chr(hexdec($hex[$i].$hex[$i+1]));
    }
    return $string;
}

function hex2str($hex) {
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
}

function bin2string($bin) {
    $res = "";
    for($p=31; $p >= 0; $p--) {
      $res .= ($bin & (1 << $p)) ? "1" : "0";
    }
    return $res;
} 

function ascii2hex($ascii) {
$hex = '';
for ($i = 0; $i < strlen($ascii); $i++) {
$byte = strtoupper(dechex(ord($ascii{$i})));
$byte = str_repeat('0', 2 - strlen($byte)).$byte;
$hex.=$byte." ";
}
return $hex;
}

function hexStringToString($hex) {
    return pack('H*', $hex);
}

function hex_dump($data, $newline="\n")
{
  static $from = '';
  static $to = '';

  static $width = 50;

  static $pad = '.';

  if ($from==='')
  {
    for ($i=0; $i<=0xFF; $i++)
    {
      $from .= chr($i);
      $to .= ($i >= 0x20 && $i <= 0x7E) ? chr($i) : $pad;
    }
  }

  $hex = str_split(bin2hex($data), $width*2);
  $chars = str_split(strtr($data, $from, $to), $width);

  $offset = 0;
  $retorno = '';
  foreach ($hex as $i => $line)
  {
    $retorno .= implode(' ', str_split($line,2));
    $offset += $width;
  }
  return $retorno;
}

function crcx25($data) {
   $content = explode(' ',$data) ;
   $len = count($content) ;
   $n = 0 ;
   $crc = 0xFFFF;   
   while ($len > 0)
   {
      $crc ^= hexdec($content[$n]);
      for ($i=0; $i<8; $i++) {
         if ($crc & 1) $crc = ($crc >> 1) ^ 0x8408;
         else $crc >>= 1;
      }
      $n++ ;
      $len-- ;
   }
   
   return(~$crc);
}

function tratarDados($dados){
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        mysqli_set_charset($link, 'utf8');
			
		$gpsSignalIndicator = 'F';
		$latitudeDecimalDegrees = $dados[1];
		$longitudeDecimalDegrees = $dados[2];
		$latitudeHemisphere = $dados[3];
		$longitudeHemisphere = $dados[4];
		$speed = $dados[5];
		$imei = $dados[6];
		$satelliteFixStatus = 'A';
		$phone = '';
		$infotext = $dados[8];
		$dataBem = null;
		$dataCliente = null;
		$ligado = (count($dados) > 9) ? $dados[9] : 'N';
		$ativo = (count($dados) > 10) ? $dados[10] : 'N';
		$realTime = (count($dados) > 11) ? $dados[11] : '';
		//D para diferencial
		
		$gpsSignalIndicator = $dados[0] == 'S' ? 'F' : 'L';
		
		//Otavio Gomes - 200120152137 
		// Estava aqui o problema do crx1 so enviar o sinal S.
		if($realTime == 'D')
			$gpsSignalIndicator = 'D';
		else
			$gpsSignalIndicator = 'R';
		
		$resBem = mysqli_query($link, "SELECT id, cliente, name, limite_velocidade FROM bem WHERE imei = '$imei'");
		//echo 'Ligado: '.$ligado;
		$dataBem = mysqli_fetch_assoc($resBem);

		if($resBem !== false){

			$resCliente = mysqli_query($link, "SELECT id, nome FROM cliente WHERE id = ".$dataBem['cliente']);
			if($resCliente !== false){
				$dataCliente = mysqli_fetch_assoc($resCliente);

				$movimento = '';
				if ($speed > 0){
					$movimento = 'S';
					if ($dataBem['limite_velocidade'] != "0" && $dataBem['limite_velocidade'] != null && $speed > $dataBem['limite_velocidade']) {
						if (!checkAlerta($imei, 'Limite Velocidade')) {
							mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Limite Velocidade')");
						}
					}
				}
				else $movimento = 'N';
				
				if ( $imei != "" ) {
					$consulta = mysqli_query($link, "SELECT * FROM geo_fence WHERE imei = '$imei'");
					while($data = mysqli_fetch_assoc($consulta)) {
						$idCerca = $data['id'];
						$imeiCerca = $data['imei'];
						$nomeCerca = $data['nome'];
						$coordenadasCerca = $data['coordenadas'];
						$resultCerca = $data['tipo'];
						$tipoEnvio = $data['tipoEnvio'];
						
						$lat_point = $latitudeDecimalDegrees;
						$lng_point = $longitudeDecimalDegrees;

						$exp = explode("|", $coordenadasCerca);

						if( count($exp) < 5 ) {
							$strExp = explode(",", $exp[0]);
							$strExp1 = explode(",", $exp[2]);
						} else {
							$int = (count($exp)) / 2;
							$strExp = explode(",", $exp[0]);
							$strExp1 = explode(",", $exp[$int]);
						}

						$lat_vertice_1 = $strExp[0];
						$lng_vertice_1 = $strExp[1];
						$lat_vertice_2 = $strExp1[0];
						$lng_vertice_2 = $strExp1[1];

						if ( $lat_vertice_1 < $lat_point Or $lat_point < $lat_vertice_2 And $lng_point < $lng_vertice_1 Or $lng_vertice_2 < $lng_point ) {
							$result = '0';
							$situacao = 'fora';
						} else {
							$result = '1';
							$situacao = 'dentro';
						}

						if ( $result == 0 And $movimento == 'S' ) {
							
							if (!checkAlerta($imei, 'Cerca Violada')){
								mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Cerca Violada')");

							}
						}
					}
				}

				if ($gpsSignalIndicator != 'L' && !empty($latitudeDecimalDegrees)) {
									
					$gpsLat = $latitudeDecimalDegrees;
					$gpsLon = $longitudeDecimalDegrees;
					$gpsLatAnt = 0;
					$gpsLatHemAnt = '';
					$gpsLonAnt = 0;
					$gpsLonHemAnt = '';
				
					$resLocAtual = mysqli_query($link, "SELECT id, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere FROM loc_atual WHERE imei = '$imei' LIMIT 1");
					$numRows = mysqli_num_rows($resLocAtual);
					
					if($numRows == 0){
						mysqli_query($link, "INSERT INTO loc_atual (date, imei, phone, satelliteFixStatus, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere, speed, infotext, gpsSignalIndicator, converte, ligado) VALUES (now(), '$imei', '$phone', '$satelliteFixStatus', '$latitudeDecimalDegrees', '$latitudeHemisphere', '$longitudeDecimalDegrees', '$longitudeHemisphere', '$speed', '$infotext', '$gpsSignalIndicator', 0, '$ligado')");
					} else {
						mysqli_query($link, "UPDATE loc_atual SET date = now(), phone = '$phone', satelliteFixStatus = '$satelliteFixStatus', latitudeDecimalDegrees = '$latitudeDecimalDegrees', latitudeHemisphere = '$latitudeHemisphere', longitudeDecimalDegrees = '$longitudeDecimalDegrees', longitudeHemisphere = '$longitudeHemisphere', speed = '$speed', infotext = '$infotext', gpsSignalIndicator = '$gpsSignalIndicator', converte = 0, ligado = '$ligado' WHERE imei = '$imei'");
					}
					
					if(!empty($latitudeDecimalDegrees)){
						mysqli_query($link, "INSERT INTO gprmc (date, imei, phone, satelliteFixStatus, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere, speed, infotext, gpsSignalIndicator, converte, ligado) VALUES (now(), '$imei', '$phone', '$satelliteFixStatus', '$latitudeDecimalDegrees', '$latitudeHemisphere', '$longitudeDecimalDegrees', '$longitudeHemisphere', '$speed', '$infotext', '$gpsSignalIndicator', 0, '$ligado')");
					}
				
					mysqli_query($link, "UPDATE bem set date = now(), status_sinal = 'R', movimento = '$movimento' WHERE imei = '$imei'");
			    	} else {
					$resLocAtual = mysqli_query($link, "SELECT id, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere FROM loc_atual WHERE imei = '$imei' LIMIT 1");
					$numRows = mysqli_num_rows($resLocAtual);
					
					if($numRows == 0){
						mysqli_query($link, "INSERT INTO loc_atual (date, imei, phone, satelliteFixStatus, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere, speed, infotext, gpsSignalIndicator, converte, ligado) VALUES (now(), '$imei', '$phone', '$satelliteFixStatus', '$latitudeDecimalDegrees', '$latitudeHemisphere', '$longitudeDecimalDegrees', '$longitudeHemisphere', '$speed', '$infotext', '$gpsSignalIndicator', 0, '$ligado')");
					} else {
						mysqli_query($link, "UPDATE loc_atual SET date = now(), phone = '$phone', satelliteFixStatus = '$satelliteFixStatus', latitudeDecimalDegrees = '$latitudeDecimalDegrees', latitudeHemisphere = '$latitudeHemisphere', longitudeDecimalDegrees = '$longitudeDecimalDegrees', longitudeHemisphere = '$longitudeHemisphere', speed = '$speed', infotext = '$infotext', gpsSignalIndicator = '$gpsSignalIndicator', converte = 0, ligado = '$ligado' WHERE imei = '$imei'");
					}
					if(!empty($latitudeDecimalDegrees)){
						mysqli_query($link, "INSERT INTO gprmc (date, imei, phone, satelliteFixStatus, latitudeDecimalDegrees, latitudeHemisphere, longitudeDecimalDegrees, longitudeHemisphere, speed, infotext, gpsSignalIndicator, converte, ligado) VALUES (now(), '$imei', '$phone', '$satelliteFixStatus', '$latitudeDecimalDegrees', '$latitudeHemisphere', '$longitudeDecimalDegrees', '$longitudeHemisphere', '$speed', '$infotext', '$gpsSignalIndicator', 0, '$ligado')");
					}
					mysqli_query($link, "UPDATE bem set date = now(), status_sinal = 'S' WHERE imei = '$imei'");
				}
				
				if(!empty($ligado)){
					mysqli_query($link, "UPDATE bem SET ligado = '$ligado' where imei = '$imei'");
				}
				
				
				if ($infotext != "tracker") {
				
					$res = mysqli_query($link, "SELECT * FROM bem WHERE imei='$imei'");
					while($data = mysqli_fetch_assoc($res)) {
						switch ($infotext) {
							case "dt":
								if (!checkAlerta($imei, 'Rastreador Desat.')) {
									$body = "Disable Track OK";
									$msg = str_replace("#TIPOALERTA", "Rastreador Desabilitado", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Rastreador Desat.')");
								}
							break;
							
							case "et":
								if (!checkAlerta($imei, 'Alarme Parado')) {
									$body = "Stop Alarm OK";
									$msg = str_replace("#TIPOALERTA", "Alarme parado", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Alarme Parado')");
								}
							break;

							case "gt";
								if (!checkAlerta($imei, 'Alarme Movimento')) {
									$body = "Move Alarm set OK";
									$msg = str_replace("#TIPOALERTA", "Alarme de Movimento ativado", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Alarme Movimento')");	
								}
							break;
							
							case "help me":
								if (!checkAlerta($imei, 'SOS!')) {
									$body = "Help!";
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'SOS!')");
									$msg = str_replace("#TIPOALERTA", "SOS", $msg);
								}
								
							break;
							
							case "ht":
								if (!checkAlerta($imei, 'Alarme Velocidade')) {
									$body = "Speed alarm set OK";
									$msg = str_replace("#TIPOALERTA", "Alarme de velocidade ativado", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Alarme Velocidade')");
								}
							break;

							case "it":
								$body = "Timezone set OK";
							break;
							
							case "low battery":
								if (!checkAlerta($imei, 'Bateria Fraca')) {
									$body = "Low battery!\nYou have about 2 minutes...";
									$msg = str_replace("#TIPOALERTA", "Bateria fraca, voce tem 2 minutos", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Bateria Fraca')");
								}
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;

							case "move":
								if (!checkAlerta($imei, 'Movimento')) {
									$body = "Move Alarm!";
									$msg = str_replace("#TIPOALERTA", "Seu veiculo esta em movimento", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Movimento')");
								}
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;

							case "nt":
								$body = "Returned to SMS mode OK";
							break;

							case "speed":
								if (!checkAlerta($imei, 'Velocidade')) {
									$body = "Speed alarm!";
									$msg = str_replace("#TIPOALERTA", "Seu veiculo ultrapassou o limite de velocidade", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Velocidade')");
								}
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;
							
							case "stockade":
								if (!checkAlerta($imei, 'Cerca')) {
									$body = "Geofence Violation!";
									$msg = str_replace("#TIPOALERTA", "Seu veiculo saiu da cerca virtual", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Cerca')");
								}
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;

							case "door alarm":
								if (!checkAlerta($imei, 'Porta')) {
									$body = "Open door!";
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Porta')");
								}
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;

							case "acc alarm":
								if (!checkAlerta($imei, 'Alarme Disparado')) {
									$body = "ACC alarm!";
									$msg = str_replace("#TIPOALERTA", "Alarme disparado", $msg);
									mysqli_query($link, "INSERT INTO message (imei, message) VALUES ('$imei', 'Alarme Disparado')");
								}
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;

							case "acc off":
								$body = "Ignicao Desligada!";
								$msg = str_replace("#TIPOALERTA", "Seu veiculo esta com a chave desligada", $msg);
								mysqli_query($link, "UPDATE bem SET ligado = 'N' where imei = '$imei'");
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;

							case "acc on":
								mysqli_query($link, "UPDATE bem SET ligado = 'S' where imei = '$imei'", $cnx);
								$send_cmd = "**,imei:". $conn_imei .",E";
								socket_send($socket, $send_cmd, strlen($send_cmd), 0);
							break;
						}
				
					}
				}
			}else {
				echo 'Cliente não encontrado. Erro: '.mysqli_error($link);
			}
		} else {
			echo 'Veículo não encontrado. Erro: '.mysqli_error($link);
		}
		mysqli_close($link);
	} else {
		echo 'Não foi possivel conectar ao banco. Erro: '.mysqli_error();
	}
}
function atualizarBemSerial($imei, $serial){
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        mysqli_set_charset($link, 'utf8');
		
		mysqli_query($link, "UPDATE bem SET serial_tracker = '$serial' WHERE imei = '$imei'");
		
		mysqli_close($link);
	} else {
		echo "Erro: ".mysqli_error($link);
	}
}
function recuperaBemSerial($imei){
        $link = mysqli_connect("Host_bd", "user_db", "Password_db", "base");
        if (!$link) {
            echo "Erro: Não é possível conectar ao MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            exit;
        mysqli_set_charset($link, 'utf8');
		$res = mysqli_query($link, "select serial_tracker from bem where imei = '$imei'");
		if($res !== false){
			$dataRes = mysqli_fetch_assoc($res);
			$serial = $dataRes['serial_tracker'];
		}
		mysqli_close($link);
	}
	return $serial;
}
function trataCommand($send_cmd, $conn_imei){
	$sizeData = 0;
	$serial = recuperaBemSerial($conn_imei);
	
	$serial = str_replace(' ', '', $serial);
	
	$decSerial = hexdec($serial);
	
	$decSerial = $decSerial+1;
	
	if($decSerial > 65535){
		$decSerial = 1;
	}
	
	$serial = dechex($decSerial);
	
	while(strlen($serial) < 4) $serial = '0'.$serial;
	
	$serial = substr($serial, 0, 2).' '.substr($serial, 2, 2);
	
	$sizeData = dechex(11 + strlen($send_cmd));
	
	while(strlen($sizeData) < 2) $sizeData = '0'.$sizeData;
	
	$lengthCommand = dechex(4+strlen($send_cmd));
	
	while(strlen($lengthCommand) < 2) $lengthCommand = '0'.$lengthCommand;
	
	$temp = $sizeData.' 80 '.$lengthCommand.' 00 00 00 00 '.$send_cmd.' '.$serial;
	
	$sendCommands = array();
	
	$crc = crcx25($temp);
	
	$crc = str_replace('ffff','',dechex($crc));
	
	$crc = strtoupper(substr($crc,0,2)).' '.strtoupper(substr($crc,2,2));
	
	$sendcmd = '78 78 '.$temp. ' ' . $crc . ' 0D 0A';
	
	$sendCommands = explode(' ', $sendcmd);
	
	$sendcmd = '';
	for($i=0; $i<count($sendCommands); $i++){
		if($i < 9 || $i >=10){
			$sendcmd .= chr(hexdec(trim($sendCommands[$i])));
		} else {
			$sendcmd .= trim($sendCommands[$i]);
		}
	}
	
	return $sendcmd;
}
function GetCrc16($pData, $nLength) {
  $crctab16 = array(
    0X0000, 0X1189, 0X2312, 0X329B, 0X4624, 0X57AD, 0X6536, 0X74BF,
    0X8C48, 0X9DC1, 0XAF5A, 0XBED3, 0XCA6C, 0XDBE5, 0XE97E, 0XF8F7,
    0X1081, 0X0108, 0X3393, 0X221A, 0X56A5, 0X472C, 0X75B7, 0X643E,
    0X9CC9, 0X8D40, 0XBFDB, 0XAE52, 0XDAED, 0XCB64, 0XF9FF, 0XE876,
    0X2102, 0X308B, 0X0210, 0X1399, 0X6726, 0X76AF, 0X4434, 0X55BD,
    0XAD4A, 0XBCC3, 0X8E58, 0X9FD1, 0XEB6E, 0XFAE7, 0XC87C, 0XD9F5,
    0X3183, 0X200A, 0X1291, 0X0318, 0X77A7, 0X662E, 0X54B5, 0X453C,
    0XBDCB, 0XAC42, 0X9ED9, 0X8F50, 0XFBEF, 0XEA66, 0XD8FD, 0XC974,
    0X4204, 0X538D, 0X6116, 0X709F, 0X0420, 0X15A9, 0X2732, 0X36BB,
    0XCE4C, 0XDFC5, 0XED5E, 0XFCD7, 0X8868, 0X99E1, 0XAB7A, 0XBAF3,
    0X5285, 0X430C, 0X7197, 0X601E, 0X14A1, 0X0528, 0X37B3, 0X263A,
    0XDECD, 0XCF44, 0XFDDF, 0XEC56, 0X98E9, 0X8960, 0XBBFB, 0XAA72,
    0X6306, 0X728F, 0X4014, 0X519D, 0X2522, 0X34AB, 0X0630, 0X17B9,
    0XEF4E, 0XFEC7, 0XCC5C, 0XDDD5, 0XA96A, 0XB8E3, 0X8A78, 0X9BF1,
    0X7387, 0X620E, 0X5095, 0X411C, 0X35A3, 0X242A, 0X16B1, 0X0738,
    0XFFCF, 0XEE46, 0XDCDD, 0XCD54, 0XB9EB, 0XA862, 0X9AF9, 0X8B70,
    0X8408, 0X9581, 0XA71A, 0XB693, 0XC22C, 0XD3A5, 0XE13E, 0XF0B7,
    0X0840, 0X19C9, 0X2B52, 0X3ADB, 0X4E64, 0X5FED, 0X6D76, 0X7CFF,
    0X9489, 0X8500, 0XB79B, 0XA612, 0XD2AD, 0XC324, 0XF1BF, 0XE036,
    0X18C1, 0X0948, 0X3BD3, 0X2A5A, 0X5EE5, 0X4F6C, 0X7DF7, 0X6C7E,
    0XA50A, 0XB483, 0X8618, 0X9791, 0XE32E, 0XF2A7, 0XC03C, 0XD1B5,
    0X2942, 0X38CB, 0X0A50, 0X1BD9, 0X6F66, 0X7EEF, 0X4C74, 0X5DFD,
    0XB58B, 0XA402, 0X9699, 0X8710, 0XF3AF, 0XE226, 0XD0BD, 0XC134,
    0X39C3, 0X284A, 0X1AD1, 0X0B58, 0X7FE7, 0X6E6E, 0X5CF5, 0X4D7C,
    0XC60C, 0XD785, 0XE51E, 0XF497, 0X8028, 0X91A1, 0XA33A, 0XB2B3,
    0X4A44, 0X5BCD, 0X6956, 0X78DF, 0X0C60, 0X1DE9, 0X2F72, 0X3EFB,
    0XD68D, 0XC704, 0XF59F, 0XE416, 0X90A9, 0X8120, 0XB3BB, 0XA232,
    0X5AC5, 0X4B4C, 0X79D7, 0X685E, 0X1CE1, 0X0D68, 0X3FF3, 0X2E7A,
    0XE70E, 0XF687, 0XC41C, 0XD595, 0XA12A, 0XB0A3, 0X8238, 0X93B1,
    0X6B46, 0X7ACF, 0X4854, 0X59DD, 0X2D62, 0X3CEB, 0X0E70, 0X1FF9,
    0XF78F, 0XE606, 0XD49D, 0XC514, 0XB1AB, 0XA022, 0X92B9, 0X8330,
    0X7BC7, 0X6A4E, 0X58D5, 0X495C, 0X3DE3, 0X2C6A, 0X1EF1, 0X0F78,
  );
  $fcs = 0xffff;
  $i = 0;
  while($nLength>0){
    $fcs = ($fcs >> 8) ^ $crctab16[($fcs ^ ord($pData{$i})) & 0xff];
    $nLength--;
    $i++;
  }
  return ~$fcs & 0xffff;
}