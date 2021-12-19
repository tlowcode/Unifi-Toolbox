<?php

declare(strict_types=1);

if (!defined('vtBoolean')) {
    define('vtBoolean', 0);
    define('vtInteger', 1);
    define('vtFloat', 2);
    define('vtString', 3);
    define('vtArray', 8);
    define('vtObject', 9);
}
	class UniFiDeviceBlocker extends IPSModule
	{
		public function Create() {
			//Never delete this line!
			parent::Create();

			$this->RegisterPropertyInteger("ControllerType", 0);
			$this->RegisterPropertyString("ServerAdress","192.168.1.1");
			$this->RegisterPropertyInteger("ServerPort", "443");
			$this->RegisterPropertyString("Site","default");
			$this->RegisterPropertyString("UserName","");
			$this->RegisterPropertyString("Password","");
			//$this->RegisterPropertyInteger("Timer", "0");

			$this->RegisterPropertyString("Devices", "");

		}

		public function Destroy() {
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges() {
			//Never delete this line!
			parent::ApplyChanges();

			$vpos = 100;

			//Create Devices mentioned in configuration
			$DevicesList = $this->ReadPropertyString("Devices");
			$DevicesJSON = json_decode($DevicesList,true);
			//var_dump($DevicesJSON);

			if (isset($DevicesJSON)) {
				foreach ($DevicesJSON as $Device) {
					$DeviceName = $Device["varDeviceName"];
					$DeviceNameClean = str_replace(array("-",":"," "), "", $DeviceName);

					if (@IPS_GetObjectIDByIdent($DeviceNameClean, $this->InstanceID) == false) {

						$DeviceNameCleanID = IPS_CreateVariable(0);
						IPS_SetName($DeviceNameCleanID, $DeviceNameClean);
						IPS_SetIdent($DeviceNameCleanID, $DeviceNameClean);
						IPS_SetParent($DeviceNameCleanID, $this->InstanceID);
						 
						SetValue($DeviceNameCleanID,true);
						$this->EnableAction($DeviceNameClean);
						$this->RegisterMessage($DeviceNameCleanID, VM_UPDATE);

					}

					/*
					
					$this->MaintainVariable($DeviceNameClean, $DeviceName, vtBoolean, "~Switch", $vpos++, isset($DevicesJSON));
					$DeviceNameCleanID = @IPS_GetObjectIDByIdent($DeviceNameClean, $this->InstanceID);
					SetValue($DeviceNameCleanID,true); // make a device will not a disconnected when the module is initialized

					$this->EnableAction($DeviceNameClean);
					
					//$DeviceNameCleanID = @IPS_GetObjectIDByIdent($DeviceNameClean, $this->InstanceID);
					if (IPS_GetObject($DeviceNameCleanID)['ObjectType'] == 2) {
							$this->RegisterMessage($DeviceNameCleanID, VM_UPDATE);
					}
					*/
					
				}
			}
		}

		public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		
			$this->SendDebug($this->Translate("Sender"),$SenderID, 0);
			$this->SetBuffer("SenderID",$SenderID);
			$this->AuthenticateAndProcessRequest();

		}


		public function AuthenticateAndProcessRequest() {
			
			$ControllerType = $this->ReadPropertyInteger("ControllerType");
			$ServerAdress = $this->ReadPropertyString("ServerAdress");
			$ServerPort = $this->ReadPropertyInteger("ServerPort");
			$Username = $this->ReadPropertyString("UserName");
			$Password = $this->ReadPropertyString("Password");
			$Site = $this->ReadPropertyString("Site");

			////////////////////////////////////////
			//Change the Unifi API to be called here
			$UnifiAPI = "api/s/".$Site."/cmd/sta";
			////////////////////////////////////////

			$ch = curl_init();

			if ($ControllerType == 0) {
				$SuffixURL = "/api/auth/login";
				curl_setopt($ch, CURLOPT_POSTFIELDS, "username=".$Username."&password=".$Password);
			}
			elseif ($ControllerType == 1) {
				$SuffixURL = "/api/login";
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $Username, 'password' => $Password]));
			}				
			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_URL, "https://".$ServerAdress.":".$ServerPort.$SuffixURL);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);  
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
			$data = curl_exec($ch);

			$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$body        = trim(substr($data, $header_size));
			$code        = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			$this->SendDebug($this->Translate("Authentication"),$this->Translate('Cookie Provided is: ').$code,0);
			preg_match_all('|Set-Cookie: (.*);|U', substr($data, 0, $header_size), $results);
			if (isset($results[1])) {
				$Cookie = implode(';', $results[1]);
				if (!empty($body)) {
					if ($code == 200) { 
						$this->SendDebug($this->Translate("Authentication"),$this->Translate('Login Successful'),0); 
						$this->SendDebug($this->Translate("Authentication"),$this->Translate('Cookie Provided is: ').$Cookie,0);
					}
					if ($code == 400) {
							$this->SendDebug($this->Translate("Authentication"),$this->Translate('Login Failure - We have received an HTTP response status: 400. Probably a controller login failure or no device is configured'),0);
			
					}
				}
			}

			$SenderID = $this->GetBuffer("SenderID");
			if ($SenderID != "") {
				$SenderObjectData = IPS_GetObject($SenderID);
				$SenderName = ($SenderObjectData["ObjectName"]);
				$SenderStatus = GetValue($SenderID);

				//Get MAC Adress from Config form
				$DevicesList = $this->ReadPropertyString("Devices");
				$DevicesJSON = json_decode($DevicesList,true);
				
				if (isset($DevicesJSON)) {
					foreach ($DevicesJSON as $Device) {
						if ($SenderName == $Device["varDeviceName"]) {
							$DeviceMacAdress = $Device["varDeviceMAC"];
							$this->SendDebug($this->Translate("Device Blocker"),$this->Translate("Device to be blocked ").$Device["varDeviceName"].$this->Translate(" device from Sender ").$SenderName,0);
						}
					}
				}

				
				$this->SendDebug($this->Translate("Device Blocker"),$Cookie,0);

				//////////////////////////////////////////
				//Change the Unifi API to be called here
				$UnifiAPI = "api/s/".$Site."/cmd/stamgr";
				//////////////////////////////////////////
				
				//create XSRF Token

				if (($Cookie) && strpos($Cookie, 'TOKEN') !== false) {
					$cookie_bits = explode('=', $Cookie);
					if (empty($cookie_bits) || !array_key_exists(1, $cookie_bits)) {
						return;
					}

					$jwt_components = explode('.', $cookie_bits[1]);
					if (empty($jwt_components) || !array_key_exists(1, $jwt_components)) {
						return;
					}

					$X_CSRF_Token = 'x-csrf-token: ' . json_decode(base64_decode($jwt_components[1]))->csrfToken;
				}

				if (isset($Cookie)) {

					$this->SendDebug($this->Translate("Device Blocker"),$this->Translate("Module is authenticated and will try to manage device"),0);

					if ($SenderStatus == 1) {
						$Command = "unblock-sta";
						$this->SendDebug($this->Translate("Device Blocker"),$this->Translate("Module will try to unblock device ").$SenderName.$this->Translate(" with MAC adress ").$DeviceMacAdress,0);
					} 
					else if ($SenderStatus == 0) {
						$Command = "block-sta";
						$this->SendDebug($this->Translate("Device Blocker"),$this->Translate("Module will try to block devie ").$SenderName.$this->Translate(" with MAC adress ").$DeviceMacAdress,0);
					}

					//$CommandToController = json_encode(array($Command => $DeviceMacAdress));
					
					$CommandToController = json_encode(array(
						"cmd" => $Command,
						"mac" => $DeviceMacAdress

					), JSON_UNESCAPED_SLASHES);
					//var_dump($CommandToController);
					

					$ch = curl_init();
					if ($ControllerType == 0) {
						$MiddlePartURL = "/proxy/network/";
					}
					elseif ($ControllerType == 1) {
						$MiddlePartURL = "/";
					}	
					curl_setopt($ch, CURLOPT_POST, true);
					curl_setopt($ch, CURLOPT_URL, "https://".$ServerAdress.":".$ServerPort.$MiddlePartURL.$UnifiAPI);
					curl_setopt($ch, CURLOPT_HTTPHEADER, array('Cookie:'.$Cookie,$X_CSRF_Token,'Content-Type:application/json', 'Expect:'/*,'data='.$CommandToController*/));
					curl_setopt($ch, CURLOPT_POSTFIELDS, $CommandToController);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); 
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);	
					curl_setopt($ch, CURLOPT_SSLVERSION, 'CURL_SSLVERSION_TLSv1');
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
					$RawData = curl_exec($ch);
					$HTTP_Code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					$this->SendDebug($this->Translate("Device Blocker"),$this->Translate("Feedback from UniFi Controller: ").$RawData." / HTTP Message ".$HTTP_Code ,0);
					curl_close($ch);

				}

			}

			

		}
		
		public function RequestAction($Ident, $Value) {
		
			$this->SetValue($Ident, $Value);
		
		}

	}
