<?php
class Switchboard {
    private $connection;
    private $currentMsg  = 1;
    
    private function connectToServer($server = '', $port = 1863) {
        if (is_resource($this->connection) === true) {
            unset($this->connection);
        }
        $this->connection = @fsockopen($server, $port, $errorno, $errorstr, 5);
        if ($this->connection === false) {
            $this->outputMessage(0, 'Failed to connect to ' . $server . ':' . $port);
            return false;
        }
        stream_set_blocking($this->connection, 0);
        $this->server = $server;
        $this->port   = $port;
        $this->outputMessage(1, 'Connected to ' . $server . ':' . $port . ' successfully!');
    }
    
    public function callUser($ourUsername, $username, $server, $hash) {
        $server = explode(':', $server);
        $this->connectToServer($server[0], $server[1]);
        $this->sendCommand('USR ? ' . $ourUsername . ' ' . $hash);
        while (feof($this->connection) === false) {
            $response = $this->readResponse();
            $this->outputMessage(3, $response);
            $command = explode(' ', $response);
            switch ($command[0]) {
                case 'USR':
                    if ($command[2] != 'OK') {
                        $this->outputMessage(2, 'We failed to contact ' . $username);
                        return false;
                    } else {
                        $this->sendCommand('CAL ? ' . $username);
                    }
                    break;
                case 'JOI':
                    $this->outputMessage(1, $username . ' joined the conversation and we can now talk to them!');
                    return true;
                default:
                    break;
            }
        }
    }
    
    public function answerCall($ourUsername, $server, $hash, $session) {
        $server = explode(':', $server);
        $this->connectToServer($server[0], $server[1]);
        $this->sendCommand('ANS ? ' . $ourUsername . ' ' . $hash . ' ' . $session);
        while (feof($this->connection) === false) {
            if ($wait === true) {
                $response .= "\r\n" . $this->readResponse();
            } else {
                $response = $this->readResponse();
            }
            $onHold = '';
            $responseArray = $this->explodeResponse($response);
            for ($i=0; $i<count($responseArray); $i++) {
                $this->outputMessage(3, $responseArray[$i]);
                $command = explode(' ', $responseArray[$i]);
                switch ($command[0]) {
                    case 'ANS':
                        if ($command[2] != 'OK') {
                            $this->outputMessage(2, 'We failed to join the conversation with ' . $username);
                            return false;
                        } else {
                            return true;
                        }
                        break;
                    default:
                        break;
                }
                if ($wait === true) {
                    break;
                }
            }
        }
    }
    
    public function checkMessages() {
        $messages = $this->readResponse();
        $command  = explode(' ', $messages);
        if ($command[0] == 'MSG') {
            $messages .= "\r\n" . $this->readResponse($command[3]);
            $messages = explode("\r\n", $messages);
            if ($messages[5] == '') {
                unset($messages[5]);
                $messages = array_values($messages);
            }
            if (stripos($messages[5], 'Chat-Logging') !== false) {
                return false;
            }
            return $messages;
        } else if ($command[0] == 'ACK') {
            $this->outputMessage(3, $messages);
            // We need to log whether the message went through properly!
        } else if ($command[0] == 'BYE') {
            $this->outputMessage(1, 'The conversation has been closed by the servers');
            return 'BYE';
        } else {
            if ($messages != '') {
                $this->outputMessage(3, $messages);
            }
            return false;
        }
    }
    
    public function sendMessage($message) {
        $header = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-MMS-IM-Format: FN=Arial; EF=; CO=0; CS=0; PF=22\r\n\r\n";
        $toSend = $header . $message;
        $this->sendCommand('MSG ? A ' . strlen($toSend), $toSend);
    }
    
    private function sendCommand($command, $data = '') {
        if (substr($command, 4, 1) != '?') {
            return false;
        }
        $command = substr($command, 0, 4) . $this->currentMsg . substr($command, 5);
        $this->currentMsg++;
        $this->outputMessage(2, $command . "\r\n" . $data);
        fwrite($this->connection, $command . "\r\n" . $data);
        // return $this->readResponse();
    }
    
    private function readResponse($length = '') {
        if ($length != '') {
            $buffer = '';
            $killLoop = 0;
            while (strlen($buffer) < $length) {
                $killLoop++;
                if ($killLoop == 100) {
                    break;
                }
                $buffer .= fgets($this->connection, ($length - strlen($buffer) + 1));
            }
        } else {
            $buffer = trim(fgets($this->connection, 4096));
        }
		return $buffer;
    }
    
    private function outputMessage($type, $message) {
        if (($this->output == false && $type != 0) || $message == '') {
            return;
        }
        $message = htmlentities(print_r($message, true));
        switch ($type) {
            case 0:
                echo '<font color="red"> ' . $message . '</font>';
                break;
            case 1:
                echo '<font color="green">I -- ' . $message . '</font>';
                break;
            case 2:
                echo '<font color="green">C >> ' . $message . '</font>';
                break;
            case 3:
                echo '<font color="blue">S << ' . $message . '</font>';
                break;
        }
        echo "<br />\r\n<br />\r\n<script>scrollTo(0,999999);</script>";
        flush();
    }
}
?>