<?php
// We need to set a timelimit of 0 so the script runs infinitely
set_time_limit(0);

// Lets require all related classes
require_once 'auth.class.php';
require_once 'challenge.class.php';
require_once 'xml2array.class.php';
require_once 'switchboard.php';

class MSN {
    private $connection;
    private $currentMsg = 1;
    private $nickname   = '';
    private $doublePing = false;
    private $authenticated = false;
    public  $loopTime   = 1000;
    public  $output     = false;
    public  $functions  = array('messageReceived',
                                'friendStatusChanged', 
                                'incomingCall', 
                                'beforeLoop',
                                'afterLoop');
    
    /**
     * Sign into msn with the specified account information
     * 
     * @author Spenser Jones
     * @param  string $email contains the email address to use in authentication
     * @param  string $password contains the password used in authentication
     * @param  string $nickname is an optional parameter that defaults to $email
     */
    public function signIn($email, $password, $nickname = '') {
        if (empty($nickname) === true) {
            $nickname = $email;
        }
        $this->nickname = rawurlencode($nickname);
        $this->email = $email;
        $this->password = $password;
        $this->handleCommunications();
    }
    
    /**
     * Connect to the messenger server and save (or replace if one already exists) the connection
     * 
     * @author Spenser Jones
     * @param  string $server defaults to the standard roundrobin messenger gateway
     * @param  int $port defaults to the standard messenger port
     */
    private function connectToServer($server = 'messenger.hotmail.com', $port = 1863) {
        if (is_resource($this->connection) === true) {
            unset($this->connection);
            $this->authenticated = false;
        }
        $this->connection = @fsockopen($server, $port, $errorno, $errorstr, 5);
        if ($this->connection === false) {
            $this->outputMessage(0, 'Failed to connect to ' . $server . ':' . $port);
        }
        stream_set_blocking($this->connection, 0);
        $this->server = $server;
        $this->port   = $port;
        $this->outputMessage(1, 'Connected to ' . $server . ':' . $port . ' successfully!');
    }
    
    public function handleCommunications() {
        $this->connectToServer();
        $this->sendCommand('VER ? MSNP15 CVR0');
        while (feof($this->connection) === false) {
            if ($this->authenticated == true) {
                $this->runUserFunction('beforeLoop');
            }
            $response = $this->readResponse();
            $this->outputMessage(3, $response);
            $command = explode(' ', $response);
            if (method_exists($this, 'respondTo' . $command[0]) === true) {
                call_user_func(array($this, 'respondTo' . $command[0]), $command);
            }
            if (count($this->convos) > 0) {
                foreach ($this->convos as $convo) {
                    while (($messages =  $convo->checkMessages()) !== false) {
                        if ($messages == 'BYE') {
                            unset($this->convos[array_search($convo, $this->convos)]);
                        } else {
                            $this->outputMessage(3, $messages);
                            $this->runUserFunction('messageReceived', $messages, $convo);
                        }
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
    }
    
    private function respondToVER($command) {
        $this->sendCommand('CVR ? 0x0409 winnt 5.1 i386 MSG80BETA 8.0.0566 msmsgs ' . $this->email);
    }
    
    private function respondToCVR($command) {
        $this->sendCommand('USR ? TWN I ' . $this->email);
    }
    
    private function respondToXFR($command) {
        $server = explode(':', $command[3]);
        if ($command[2] == 'NS') {
            $this->currentMsg = 1;
            if ($this->connectToServer($server[0], $server[1]) === false) {
                exit;
            }
            $this->sendCommand('VER ? MSNP15 CVR0');
        } else if ($command[2] == 'SB') {
            $switchboard = new Switchboard();
            $switchboard->output = $this->output;
            foreach ($this->convoQueue as $toCall => $message) {
                if ($switchboard->callUser($this->email, $toCall, $command[3], $command[5]) === true) {
                    $this->convos[$toCall] = $switchboard;
                    $this->outputMessage(1, 'We can now talk to ' . $toCall);
                    $this->convos[$toCall]->sendMessage($message);
                    unset($this->convoQueue[$toCall]);
                    break;
                }
            }
        }
    }
    
    private function respondToGCF($command) {
        // We don't actually use this at all, but save it just incase there are future changes
        $this->policyXML = $this->readResponse($command[2]);
    }
    
    private function respondToUSR($command) {
        if ($command[2] == 'TWN') {
            $ticketMaster = new Authentication();
            $ticket = $ticketMaster->getTicket($this->email, $this->password, $command[4]);
            if ($ticket === false) {
                $this->outputMessage(0, 'We failed to authenticate ourselves!');
            }
            $this->sendCommand('USR ? TWN S ' . $ticket);
        } else if ($command[2] != 'OK') {
            $this->outputMessage(0, 'Something went wrong when authenticating with our TWN');
        }
    }
    
    private function respondToMSG($command) {
        $profile = explode("\r\n", $this->readResponse($command[3]));
        foreach ($profile as $thisProfile) {
            $thisProfile = explode(': ', $thisProfile);
            if (count($thisProfile) == 2) {
                $this->profile[$thisProfile[0]] = $thisProfile[1];
            }
        }
        $this->getMembershipList();
        $this->getAddressBook();
        $this->sendCommand('BLP ? AL');
    }
    
    private function respondToBLP($command) {
        $this->sendCommand('ADL ? ' . strlen($this->adl), $this->adl);
        $this->sendCommand('PRP ? MFN ' . $this->nickname);
        $this->sendCommand('CHG ? NLN');
    }
    
    private function respondToCHG($command) {
        $this->authenticated = true;
    }
    
    private function respondToUBX($command) {
        $response = $this->readResponse($command[3]);
        $this->outputMessage(1, 'We don\'t handle Personal Status Messages or Display Pictures right now');
    }
    
    private function respondToFLN($command) {
        $this->friends[$command[1]]['status'] = 'FLN';
        $this->outputMessage(1, $command[1] . ' just changed their status to FLN');
        $this->runUserFunction('friendStatusChanged', $command[1], 'FLN');
    }
    
    private function respondToNLN($command) {
        $this->friends[$command[2]]['status'] = 'NLN';
        $this->friends[$command[2]]['name']   = urldecode($command[4]);
        $this->outputMessage(1, $command[4] . ' just changed their status to NLN');
        $this->runUserFunction('friendStatusChanged', $command[2], $command[1], $command[4]);
    }
    
    private function respondToILN($command) {
        $this->friends[$command[3]]['status'] = $command[2];
        $this->friends[$command[3]]['name']   = urldecode($command[5]);
        $this->outputMessage(1, $command[3] . ' just changed their status to ' . $command[2]);
        $this->runUserFunction('friendStatusChanged', $command[3], $command[2], $command[5]);
    }
    
    private function respondToCHL($command) {
        $challenge = new Challenge();
        $this->sendCommand('QRY ? PROD0090YUAUV{2B 32', $challenge->generateCHLHash($command[2], 'PROD0090YUAUV{2B'));
    }
    
    private function respondToRNG($command) {
        if ($this->runUserFunction('incomingCall', $command) === false) {
            $switchboard = new Switchboard();
            $switchboard->output = $this->output;
            if ($switchboard->answerCall($this->email, $command[2], $command[4], $command[1]) === true) {
                $this->convos[$command[5]] = $switchboard;
                $this->outputMessage(1, 'We can now talk to ' . $command[6] . ' (' . $command[5] . ')');
            }
        }
    }
    
    private function respondToQNG($command) {
        $this->nextPing   = mktime() + $command[1];
        $this->doublePing = false;
    }
    
    private function respondToADL($command) {
        $adl = $this->readResponse($command[2]);
        $this->outputMessage(1, 'ADD REQUEST: "' . $adl . '"' . $command[2]);
        flush();
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
    
    public function messageUser($email, $message) {
        if ($email == '' || $message == '') {
            return false;
        }
        if (isset($this->convos[$email]) === false) {
            if ($this->convoQueue[$email] == '') {
                $this->convoQueue[$email] =  $message;
                $this->sendCommand('XFR ? SB');
            } else {
                $this->convoQueue[$email] .= "\r\n" . $message;
            }
        } else {
            $this->convos[$email]->sendMessage($message);
        }
    }
    
    public function changeNickname($nickname) {
        if ($this->nickname == $nickname) {
            return true;
        }
        $this->nickname = str_replace(' ', '%20', $nickname);
        $this->sendCommand('PRP ? MFN ' . $this->nickname);
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
            $buffer .= fgets($this->connection, ($length - strlen($buffer) + 1));
            while (strlen($buffer) < $length) {
                usleep(100 * 1000); // Sleep for 100ms
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
                exit;
                break;
            case 1:
                echo '<font color="grey">I -- ' . $message . '</font>';
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