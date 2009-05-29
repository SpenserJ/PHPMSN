<?php
require_once 'auth.class.php';
require_once 'challenge.class.php';
require_once 'xml2array.class.php';
require_once 'switchboard.php';

class MSN {
    private $connection;
    private $currentMsg = 1;
    private $nickname   = '';
    private $doublePing = false;
    public  $loopTime   = 1000;
    public  $output     = false;
    public  $functions  = array('messageReceived',
                                'friendStatusChanged', 
                                'incomingCall', 
                                'beforeLoop',
                                'afterLoop');
    
    public function signIn($username, $password, $nickname) {
        $this->nickname = str_replace(' ', '%20', $nickname);
        $this->connect($username, $password);
        $this->handleNS();
    }
    
    private function connectToServer($server = 'messenger.hotmail.com', $port = 1863) {
        if (is_resource($this->connection) === true) {
            unset($this->connection);
        }
        $this->connection = @fsockopen($server, $port, $errorno, $errorstr, 5);
        if ($this->connection === false) {
            $this->outputMessage(0, 'Failed to connect to ' . $server . ':' . $port);
            return false;
        }
        $this->server = $server;
        $this->port   = $port;
        $this->outputMessage(1, 'Connected to ' . $server . ':' . $port . ' successfully!');
    }
    
    private function checkConnection($tryConnect = true, $server = 'messenger.hotmail.com', $port = 1863) {
        $connected = is_resource($this->connection);
        if ($connected === false && $tryConnect === true) {
            return $this->connectToServer($server, $port);
        } else {
            return $connected;
        }
    }
    
    public function connect($username, $password) {
        $this->username = $username;
        $this->password = $password;
        $this->connectToServer();
        $this->sendCommand('VER ? MSNP15 CVR0');
        $wait = false;
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
                    case 'VER':
                        $this->sendCommand('CVR ? 0x0409 winnt 5.1 i386 MSG80BETA 8.0.0566 msmsgs ' . $username);
                        break;
                    case 'CVR':
                        $this->sendCommand('USR ? TWN I ' . $username);
                        break;
                    case 'XFR':
                        $server = explode(':', $command[3]);
                        $this->currentMsg = 1;
                        if ($this->connectToServer($server[0], $server[1]) === false) {
                            exit;
                        }
                        $this->sendCommand('VER ? MSNP15 CVR0');
                        break;
                    case 'GCF':
                        $this->policyXML = $this->readResponse($command[2]);
                        break;
                    case '<Policies><Policy':
                        if (strpos($responseArray[$i], '</Policies>') === false) {
                            $wait = true;
                            break;
                        } else {
                            $wait = false;
                            $this->policyXML = substr($responseArray[$i], 0, strpos($responseArray[$i], '</Policies>') + strlen('</Policies>'));
                            $command = explode(' ', substr($responseArray[$i], strpos($responseArray[$i], '</Policies>') + strlen('</Policies>')));
                            break;
                        }
                    case 'USR':
                        if ($command[2] == 'TWN') {
                            $ticketMaster = new Authentication();
                            $ticket = $ticketMaster->getTicket($this->username, $this->password, $command[4]);
                            if ($ticket === false) {
                                $this->outputMessage(0, 'We failed to authenticate ourselves!');
                                exit;
                            }
                            $this->sendCommand('USR ? TWN S ' . $ticket);
                        } else if ($command[2] == 'OK') {
                            //return true;
                        } else {
                            $this->outputMessage(0, 'Say wha?');
                            exit;
                        }
                        break;
                    case 'MSG':
                        $profile = explode("\r\n", $this->readResponse($command[3]));
                        foreach ($profile as $thisProfile) {
                            $thisProfile = explode(': ', $thisProfile);
                            if (count($thisProfile) == 2) {
                                $this->profile[$thisProfile[0]] = $thisProfile[1];
                            }
                        }
                        if ($this->profile['Content-Type'] == 'text/x-msmsgsprofile; charset=UTF-8') {
                            $this->getMembershipList();
                            $this->getAddressBook();
                            $this->sendCommand('BLP ? AL');
                        }
                        break;
                    case 'BLP':
                        $this->sendCommand('ADL ? ' . strlen($this->adl), $this->adl);
                        $this->sendCommand('PRP ? MFN ' . $this->nickname);
                        $this->sendCommand('CHG ? NLN');
                        break;
                    case 'ADL':
                        break;
                    case 'PRP':
                        break;
                    case 'CHG':
                        return true;
                        break;
                    default:
                        break;
                }
                if ($wait === true) {
                    break;
                }
            }
        }
        $this->outputMessage(0, 'Connection was terminated. We did a bad thing :(');
        exit;
    }
    
    public function handleNS() {
        stream_set_blocking($this->connection, 0);
        while (feof($this->connection) === false) {
            $this->runUserFunction('beforeLoop');
            $response = $this->readResponse();
            if ($waitFor == '') {
                $continue = true;
            } else if ($waitFor != '' && strpos($response, $waitFor) !== false) {
                $continue = true;
                $onHold .= $response;
            } else {
                $continue = false;
                $onHold .= $response . "\r\n";
            }
            if ($continue === true) {
                if ($onHold != '') {
                    $response = $onHold;
                }
                $onHold = '';
                $responseArray = $this->explodeResponse($response);
                for ($i=0; $i<count($responseArray); $i++) {
                    $this->outputMessage(3, $responseArray[$i]);
                    $command = explode(' ', $responseArray[$i]);
                    switch ($command[0]) {
                        case 'MSG':
                            $this->readResponse($command[3]);
                            $this->outputMessage(1, 'We don\'t care about all they just gave us!');
                            break;
                        case 'FLN':
                            $this->friends[$command[1]]['status'] = 'FLN';
                            $this->outputMessage(1, $command[1] . ' just changed their status to FLN');
                            $this->runUserFunction('friendStatusChanged', $command[1], 'FLN');
                            break;
                        case 'NLN':
                            $this->friends[$command[2]]['status'] = 'NLN';
                            $this->friends[$command[2]]['name']   = urldecode($command[4]);
                            $this->outputMessage(1, $command[4] . ' just changed their status to NLN');
                            $this->runUserFunction('friendStatusChanged', $command[2], $command[1], $command[4]);
                            break;
                        case 'ILN':
                            $this->friends[$command[3]]['status'] = $command[2];
                            $this->friends[$command[3]]['name']   = urldecode($command[5]);
                            $this->outputMessage(1, $command[3] . ' just changed their status to ' . $command[2]);
                            $this->runUserFunction('friendStatusChanged', $command[3], $command[2], $command[5]);
                            break;
                        case 'UBX':
                            $ubx = $this->readResponse($command[3]);
                            $this->outputMessage(1, 'We don\'t care about PSMs!');
                            break;
                        case 'CHL':
                            $challenge = new Challenge();
                            $this->sendCommand('QRY ? PROD0090YUAUV{2B 32', $challenge->generateCHLHash($command[2], 'PROD0090YUAUV{2B'));
                            break;
                        case 'RNG':
                            if ($this->runUserFunction('incomingCall', $command) === false) {
                                $switchboard = new Switchboard();
                                $switchboard->output = $this->output;
                                if ($switchboard->answerCall($this->username, $command[2], $command[4], $command[1]) === true) {
                                    $this->convos[$command[5]] = $switchboard;
                                    $this->outputMessage(1, 'We can now talk to ' . $command[6] . ' (' . $command[5] . ')');
                                }
                            }
                            break;
                        case 'XFR':
                            if ($command[2] == 'SB') {
                                $switchboard = new Switchboard();
                                $switchboard->output = $this->output;
                                foreach ($this->convoQueue as $toCall => $message) {
                                    if ($switchboard->callUser($this->username, $toCall, $command[3], $command[5]) === true) {
                                        $this->convos[$toCall] = $switchboard;
                                        $this->outputMessage(1, 'We can now talk to ' . $toCall);
                                        $this->convos[$toCall]->sendMessage($message);
                                        unset($this->convoQueue[$toCall]);
                                        break;
                                    }
                                }
                            }
                            break;
                        case 'QNG':
                            $this->nextPing   = mktime() + $command[1];
                            $this->doublePing = false;
                            break;
                        default:
                            break;
                    }
                    if ($waitFor != '') {
                        $onHold = $responseArray[$i] . "\r\n";
                    }
                }
            }
            if (count($this->convos) > 0) {
                foreach ($this->convos as $convo) {
                    while (($messages = $convo->checkMessages()) !== false) {
                        $this->outputMessage(3, $messages);
                        $this->runUserFunction('messageReceived', $messages, $convo);
                    }
                }
            }
            if ($this->nextPing <= mktime()) {
                if ($this->doublePing === true) {
                    break;
                }
                $this->sendCommand('PNG');
                $this->doublePing = true;
            }
            $this->runUserFunction('afterLoop');
            if ($this->loopTime > 0) {
                usleep($this->loopTime);
            }
        }
        $this->outputMessage(0, 'Connection was terminated. We did a bad thing :(');
        exit;
    }
    
    private function runUserFunction($function) {
        if (function_exists($this->functions[$function]) === true) {
            $args = func_get_args();
            unset($args[0]);
            call_user_func_array($this->functions[$function], $args);
            return true;
        } else {
            return false;
        }
    }
    
    public function messageUser($username, $message) {
        if (isset($this->convos[$username]) === false) {
            if ($this->convoQueue[$username] == '') {
                $this->convoQueue[$username] =  $message;
                $this->sendCommand('XFR ? SB');
            } else {
                $this->convoQueue[$username] .= "\r\n" . $message;
            }
        } else {
            $this->convos[$username]->sendMessage($message);
        }
    }
    
    public function changeNickname($nickname) {
        if ($this->nickname == $nickname) {
            return true;
        }
        $this->nickname = str_replace(' ', '%20', $nickname);
        $this->sendCommand('PRP ? MFN ' . $this->nickname);
    }
    
    private function explodeResponse($response) {
        $commands = array('VER', 'CVR', 'XFR', 'USR', 'GCF', 'MSG', 'BLP', 'ADL', 'PRP', 'CHG', 'ILN 0', 'NLN NLN', 'FLN', 'UBX', 'CHL', 'RNG');
        $responseTemp = $response;
        foreach ($commands as $command) {
            if (strpos($responseTemp, $command) !== false) {
                $responseTemp = explode($command, $responseTemp);
                $responseTemp = implode("\r\n\r\n\r\n" . $command, $responseTemp);
            }
        }
        $responseTemp = explode("\r\n\r\n\r\n", $responseTemp);
        if ($responseTemp[0] == '') {
            array_shift($responseTemp);
        }
        return $responseTemp;
    }
    
    private function getMembershipList() {
        $soap = new Authentication();
        $friends = $soap->getMembershipList($this->profile['MSPAuth']);
        $xmlObj = new XmlToArray($friends);
        $this->friendsFull = $xmlObj->createArray();
        $friends = $this->friendsFull['soap:Envelope']['soap:Body'][0]['FindMembershipResponse'][0]['FindMembershipResult'][0]['Services'][0]['Service'][0]['Memberships'][0]['Membership'];
        foreach ($this->friendsFull['soap:Envelope']['soap:Body'][0]['FindMembershipResponse'][0]['FindMembershipResult'][0]['Services'][0]['Service'] as $thisService) {
            foreach ($thisService['Memberships'][0]['Membership'] as $thisRole) {
                $roleName = $thisRole['MemberRole'];
                foreach ($thisRole['Members'][0]['Member'] as $thisFriend) {
                    $friendsFinal[$thisFriend['PassportName']]['email'] = $thisFriend['PassportName'];
                    if ($friendsFinal[$thisFriend['PassportName']]['name'] == '') {
                        $friendsFinal[$thisFriend['PassportName']]['name'] = $thisFriend['DisplayName'];
                    }
                    $friendsFinal[$thisFriend['PassportName']]['cid'] = $thisFriend['CID'];
                    $friendsFinal[$thisFriend['PassportName']][$roleName] = 'true';
                }
            }
        }
        foreach ($friendsFinal as $thisFriend) {
            $email = explode('@', $thisFriend['email']);

            if ($thisFriend['Block'] == 'true') {
                $listBit = 5;
            } else {
                $listBit = 3;
            }
            $adl[$email[1]][] = array('c' => $email[0], 'l' => $listBit);
        }        $adlFinal = "<ml l='1'>";
        $adl = array_reverse($adl);
        foreach ($adl as $domain => $members) {
            $adlFinal .= "<d n='" . $domain . "'>";
            $members = array_reverse($members);
            foreach ($members as $member) {
                $adlFinal .= "<c n='" . $member['c'] . "' l='" . $member['l'] . "' t='1'/>";
            }
            $adlFinal .= '</d>';
        }
        $this->adl = $adlFinal . '</ml>';
        $this->friends = $friendsFinal;
    }
    
    private function getAddressBook() {
        $auth = new Authentication();
        $addressBook = $auth->getAddressBook($this->profile['MSPAuth']);
        $xmlObj = new XmlToArray($addressBook);
        $this->addressBook = $xmlObj->createArray();
    }
    
    private function sendCommand($command, $data = '') {
        if ($command != 'PNG') {
            if (substr($command, 4, 1) != '?') {
                return false;
            }
            $command = substr($command, 0, 4) . $this->currentMsg . substr($command, 5);
            $this->currentMsg++;
        }
        $this->outputMessage(2, $command . "\r\n" . $data);
        fwrite($this->connection, $command . "\r\n" . $data);
        $this->nextPing = mktime() + 45;
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
        if ($this->output == false && $type != 0) {
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