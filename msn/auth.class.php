<?php
class Authentication {
    private function getClean($url, $action, $xml, $auth) {
        $header_array = array('SOAPAction: ' . $action, 'Content-Type: text/xml; charset=utf-8');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
        curl_setopt($curl, CURLOPT_COOKIE, 'MSPAuth=' . $auth);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
        $data = curl_exec($curl);
        curl_close($curl);
        return $data;
    }
    
    public function getTicket($username, $password, $string) {
        $username = htmlspecialchars($username);
        $password = htmlspecialchars($password);
        $string	  = htmlspecialchars(str_replace(",","&",urldecode($string)));
        $XML = '<?xml version="1.0" encoding="UTF-8"?><Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/" xmlns:wsse="http://schemas.xmlsoap.org/ws/2003/06/secext" xmlns:saml="urn:oasis:names:tc:SAML:1.0:assertion" xmlns:wsp="http://schemas.xmlsoap.org/ws/2002/12/policy" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd" xmlns:wsa="http://schemas.xmlsoap.org/ws/2004/03/addressing" xmlns:wssc="http://schemas.xmlsoap.org/ws/2004/04/sc" xmlns:wst="http://schemas.xmlsoap.org/ws/2004/04/trust">  <Header>    <ps:AuthInfo xmlns:ps="http://schemas.microsoft.com/Passport/SoapServices/PPCRL" Id="PPAuthInfo">      <ps:HostingApp>{7108E71A-9926-4FCB-BCC9-9A9D3F32E423}</ps:HostingApp>      <ps:BinaryVersion>4</ps:BinaryVersion>      <ps:UIVersion>1</ps:UIVersion>      <ps:Cookies></ps:Cookies>      <ps:RequestParams>AQAAAAIAAABsYwQAAAAzMDg0</ps:RequestParams>    </ps:AuthInfo>    <wsse:Security>       <wsse:UsernameToken Id="user">         '
        .'<wsse:Username>'.$username.'</wsse:Username>          <wsse:Password>'.$password.'</wsse:Password>       '
        .'</wsse:UsernameToken>    </wsse:Security>  </Header>  <Body>    <ps:RequestMultipleSecurityTokens xmlns:ps="http://schemas.microsoft.com/Passport/SoapServices/PPCRL" Id="RSTS">      <wst:RequestSecurityToken Id="RST0">        <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>        <wsp:AppliesTo>          <wsa:EndpointReference>				            <wsa:Address>http://Passport.NET/tb</wsa:Address>          </wsa:EndpointReference>        </wsp:AppliesTo>      </wst:RequestSecurityToken>      <wst:RequestSecurityToken Id="RST1">       <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>        <wsp:AppliesTo>          <wsa:EndpointReference>            <wsa:Address>messenger.msn.com</wsa:Address>          </wsa:EndpointReference>        </wsp:AppliesTo>        '
        .'<wsse:PolicyReference URI="?'.$string.'"></wsse:PolicyReference>      </wst:RequestSecurityToken>    </ps:RequestMultipleSecurityTokens>  </Body></Envelope>';
        
        /* Setup Curl for the request. */
        $curl = curl_init(); 
        curl_setopt($curl, CURLOPT_URL, 'https://loginnet.passport.com/RST.srf');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_POST,1); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);

        $data = curl_exec($curl);
        curl_close($curl);
        
        preg_match("#<wsse\:BinarySecurityToken Id=\"PPToken1\">(.*)</wsse\:BinarySecurityToken>#",$data,$matches);
        
        if(!count($matches) > 0) {
            return false;
        } 

        return html_entity_decode($matches[1]); 
    }
    
    public function getMembershipList($auth, $update = false) {
        $xml = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
<soap:Header>
    <ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ApplicationId>996CDE1E-AA53-4477-B943-2BE802EA6166</ApplicationId>
        <IsMigration>false</IsMigration>
        <PartnerScenario>Initial</PartnerScenario>
    </ABApplicationHeader>
    <ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ManagedGroupRequest>false</ManagedGroupRequest>
    </ABAuthHeader>
</soap:Header>
<soap:Body>
    <FindMembership xmlns="http://www.msn.com/webservices/AddressBook">
        <serviceFilter>
            <Types>
                <ServiceType>Messenger</ServiceType>
                <ServiceType>Invitation</ServiceType>
                <ServiceType>SocialNetwork</ServiceType>
                <ServiceType>Space</ServiceType>
                <ServiceType>Profile</ServiceType>
            </Types>
        </serviceFilter>
    </FindMembership>
</soap:Body>
</soap:Envelope>';
        return $this->getClean('http://contacts.msn.com/abservice/SharingService.asmx', 'http://www.msn.com/webservices/AddressBook/FindMembership', $xml, $auth);

    }
    
    public function getAddressBook($auth) {
        $xml = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
	<soap:Header>
		<ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
			<ApplicationId>CFE80F9D-180F-4399-82AB-413F33A1FA11</ApplicationId>
			<IsMigration>false</IsMigration>
			<PartnerScenario>Initial</PartnerScenario>
		</ABApplicationHeader>
		<ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
			<ManagedGroupRequest>false</ManagedGroupRequest>
		</ABAuthHeader>
	</soap:Header>
	<soap:Body>
		<ABFindAll xmlns="http://www.msn.com/webservices/AddressBook">
			<abId>00000000-0000-0000-0000-000000000000</abId>
			<abView>Full</abView>
			<deltasOnly>false</deltasOnly>
			<lastChange>0001-01-01T00:00:00.0000000-08:00</lastChange>
		</ABFindAll>
	</soap:Body>
</soap:Envelope>';

        return $this->getClean('http://contacts.msn.com/abservice/abservice.asmx', 'http://www.msn.com/webservices/AddressBook/ABFindAll', $xml, $auth);
    }
    
    public function doChallenge($challenge) {
        $productKey  = 'YMM8C_H7KCQ2S_KL';
        $challenge   = '22210219642164014968';
        $md5Hash     = md5($challenge . $productKey);
        $md5HashTemp = explode("\r\n", chunk_split($md5Hash, 8));
        echo '<pre>';
        print_r($md5HashTemp);
        $md5Hash = array();
        for ($i=0; $i<strlen($md5HashTemp); $i++) {
            $set = floor($i / 8);
            $md5Hash[$set] .= dechex(ord($md5HashTemp[$i]));
        }
        print_r($md5Hash);
    }
}
?>
