<?php

define('CHARGEOVERAPI_BASEDIR', dirname(__FILE__));

require_once CHARGEOVERAPI_BASEDIR . '/ChargeOverAPI/Loader.php';

ChargeOverAPI_Loader::load('/ChargeOverAPI/Object.php');

ChargeOverAPI_Loader::load('/ChargeOverAPI/Aggregate.php');

ChargeOverAPI_Loader::load('/ChargeOverAPI/Bulk.php');

ChargeOverAPI_Loader::import('/ChargeOverAPI/Object/');

class ChargeOverAPI
{
	const ERROR_UNKNOWN = -1;
	const ERROR_RESPONSE = -2;

	const AUTHMODE_SIGNATURE_V1 = 'signature-v1';
	const AUTHMODE_HTTP_BASIC = 'http-basic';
	
	const METHOD_CREATE = 'create';
	const METHOD_MODIFY = 'modify';
	const METHOD_DELETE = 'delete';
	const METHOD_GET = 'get';
	const METHOD_FIND = 'find';
	const METHOD_ACTION = 'action';
	const METHOD_AGGREGATE = 'aggregate';
	const METHOD_BULK = 'bulk';
	const METHOD_CONFIG = 'config';
	const METHOD_CHARGEOVERJS = 'chargeoverjs';
	
	const STATUS_OK = 'OK';
	const STATUS_ERROR = 'Error';

	const FLAG_QUICKBOOKS = '_flag_quickbooks';
	const FLAG_EMAILS = '_flag_emails';
	const FLAG_WEBHOOKS = '_flag_webhooks';
	const FLAG_EVENTS = '_flag_events';
	
	protected $_url;
	protected $_authmode;
	protected $_username;
	protected $_password;
	
	protected $_last_request;
	protected $_last_response;
	protected $_last_error;

	// API flags
	protected $_flags;

	// HTTP curl options
	protected $_http;
	
	public function __construct($url, $authmode, $username, $password, $flags = array())
	{
		$this->_url = rtrim($url, '/');
		$this->_authmode = $authmode;
		$this->_username = $username;
		$this->_password = $password;
		
		$this->_last_request = null;
		$this->_last_response = null;
		$this->_last_error = null;
		
		$this->_flags = (array) $flags;
	}
	
	protected function _signature($public, $private, $url, $data)
	{
		$tmp = array_merge(range('a', 'z'), range(0, 9));
		shuffle($tmp);
		$nonce = implode('', array_slice($tmp, 0, 8));
		
		$time = time();
		
		$str = $public . '||' . strtolower($url) . '||' . $nonce . '||' . $time . '||' . $data;
		$signature = hash_hmac('sha256', $str, $private);

		//print('lib {   ' . $str . '   }' . "\n\n");
		
		return 'Authorization: ChargeOver co_public_key="' . $public . '" co_nonce="' . $nonce . '" co_timestamp="' . $time . '" co_signature_method="HMAC-SHA256" co_version="1.0" co_signature="' . $signature . '" ';
	}
	
	protected function _request($http_method, $uri, $data = null)
	{
		$public = $this->_username;
		$private = $this->_password;
		
		$endpoint = $this->_url . '/' . ltrim($uri, '/');

		if (count($this->_flags))
		{
			if (false === strpos($endpoint, '?'))
			{
				$endpoint .= '?' . http_build_query($this->_flags);
			}
			else
			{
				$endpoint .= '&' . http_build_query($this->_flags);
			}
		}
		
		/*
		if (false === strpos($endpoint, '?'))
		{
			$endpoint .= '?debug=1';
		}
		else
		{
			$endpoint .= '&debug=1';
		}
		*/
		
		$headers = array();
		$headers[] = 'Content-Type: application/json';
		
		// create a new cURL resource
		$ch = curl_init();

		$post_data = null;
		if ($data)
		{
			$post_data = json_encode($data);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}
		
		if ($this->_authmode == ChargeOverAPI::AUTHMODE_SIGNATURE_V1)
		{
			// Signed requests
			$signature = $this->_signature($public, $private, $endpoint, $post_data);
			
			$headers[] = $signature;
		}
		else if ($this->_authmode == ChargeOverAPI::AUTHMODE_HTTP_BASIC)
		{
			// HTTP basic
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($ch, CURLOPT_USERPWD, $public . ':' . $private);
		}
		
		// set URL and other appropriate options
		curl_setopt($ch, CURLOPT_URL, $endpoint);
		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

		// Force TLS
		curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'TLSv1');

		//[2/23/15, 8:14:18 AM] Keith Palmer Jr: curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		//[2/23/15, 8:14:30 AM] Keith Palmer Jr: curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		
		curl_setopt($ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ));
		
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $http_method);
		
		// Build last request string
		$this->_last_request = $http_method . ' ' . $endpoint . "\r\n\r\n";
		if ($post_data)
		{
			$this->_last_request .= $post_data;
		}
		
		$out = curl_exec($ch);
		$info = curl_getinfo($ch);

		// Log last response
		$this->_last_response = $out;
		
		if (!$out)
		{
			$err = 'Problem hitting URL [' . $endpoint . ']: ' . curl_error($ch) . ', ' . print_r(curl_getinfo($ch), true);
			curl_close($ch);

			return $this->_error($err, ChargeOverAPI::ERROR_UNKNOWN);
		}

		curl_close($ch);
		
		$data = json_decode($out);

		if (json_last_error() == JSON_ERROR_NONE)
		{
			// We at least got back a valid JSON object
			if ($data->status != ChargeOverAPI::STATUS_OK)
			{
				return $this->_error($data->message, $data->code);
			}

			return $data;
		}

		$err = 'Server returned an invalid JSON response: ' . $out . ', JSON parser returned error: ' . json_last_error();
		return $this->_error($err, ChargeOverAPI::ERROR_RESPONSE);
	}
	
	protected function _error($err, $code = 400)
	{
		$this->_last_error = $err; 

		// The response we got back wasn't valid JSON...? 
		return json_decode(json_encode(array( 
			'code' => $code, 			// let's force this to a 400 error instead, it's non-recoverable    $info['http_code'],
			'status' => ChargeOverAPI::STATUS_ERROR, 
			'message' => $err, 
			'response' => null, 
			)));
	}

	public function http($opt, $value)
	{

	}

	public function flag($flag, $value)
	{
		$this->_flags[$flag] = (int) $value;
	}

	public function lastRequest()
	{
		return $this->_last_request;
	}
	
	public function lastResponse()
	{
		return $this->_last_response;
	}
	
	public function lastError()
	{
		return $this->_last_error;
	}
	
	public function isError($Object)
	{
		//print_r($Object);

		if (!is_object($Object))
		{
			return true;
		}
		else if ($Object->status != ChargeOverAPI::STATUS_OK)
		{
			return true;
		}
		
		return false;
	}
	
	/**
	 * Map a method/id/object type to an API URL
	 * 
	 * @param string $method
	 * @param integer $id
	 * @param varies $Object_or_obj_type			Either an object, or an object type constant
	 * @return string 								The URL for the API 
	 */
	protected function _map($method, $id, $Object_or_obj_type)
	{
		if ($method == ChargeOverAPI::METHOD_AGGREGATE)
		{
			return '_aggregate';
		}
		else if ($method == ChargeOverAPI::METHOD_BULK)
		{
			return '_bulk';
		}
		else if ($method == ChargeOverAPI::METHOD_CONFIG)
		{
			return '_config';
		}

		if (is_object($Object_or_obj_type))
		{
			$obj_type = $this->classToType($Object_or_obj_type);
		}
		else
		{
			$obj_type = $Object_or_obj_type;
		}
		
		if ($method == ChargeOverAPI::METHOD_CREATE)
		{
			$id = null;
		}
		
		if ($id)
		{
			return $obj_type . '/' . $id;
		}
		else
		{
			return $obj_type;
		}
	}

	public function typeToClass($type)
	{
		$map = $this->_typeClassMap();

		if (isset($map[$type]))
		{
			return $map[$type];
		}

		return null;
	}

	public function classToType($Object_or_class)
	{
		if (is_object($Object_or_class))
		{
			$class = get_class($Object_or_class);
		}
		else
		{
			$class = $Object_or_class;
		}

		$map = array_flip($this->_typeClassMap());

		if (isset($map[$class]))
		{
			return $map[$class];
		}

		return null;
	}

	protected function _typeClassMap()
	{
		return array(
			ChargeOverAPI_Object::TYPE_CUSTOMER => 'ChargeOverAPI_Object_Customer', 
			ChargeOverAPI_Object::TYPE_USER => 'ChargeOverAPI_Object_User', 
			ChargeOverAPI_Object::TYPE_BILLINGPACKAGE => 'ChargeOverAPI_Object_BillingPackage', 
			ChargeOverAPI_Object::TYPE_PACKAGE => 'ChargeOverAPI_Object_Package', 
			ChargeOverAPI_Object::TYPE_PROJECT => 'ChargeOverAPI_Object_Project', 
			ChargeOverAPI_Object::TYPE_CREDITCARD => 'ChargeOverAPI_Object_CreditCard', 
			ChargeOverAPI_Object::TYPE_INVOICE => 'ChargeOverAPI_Object_Invoice', 
			ChargeOverAPI_Object::TYPE_TRANSACTION => 'ChargeOverAPI_Object_Transaction', 
			ChargeOverAPI_Object::TYPE_ACH => 'ChargeOverAPI_Object_Ach', 
			ChargeOverAPI_Object::TYPE_USAGE => 'ChargeOverAPI_Object_Usage', 
			ChargeOverAPI_Object::TYPE_ITEM => 'ChargeOverAPI_Object_Item',
			ChargeOverAPI_Object::TYPE_ITEMCATEGORY => 'ChargeOverAPI_Object_ItemCategory', 
			ChargeOverAPI_Object::TYPE_NOTE => 'ChargeOverAPI_Object_Note', 
			ChargeOverAPI_Object::TYPE_COUNTRY => 'ChargeOverAPI_Object_Country', 
			ChargeOverAPI_Object::TYPE_TOKENIZED => 'ChargeOverAPI_Object_Tokenized', 
			);
	}
	
	public function rawRequest($method, $uri, $data)
	{
		
	}

	public function bulk($Bulk)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_BULK, null, null);
		
		return $this->_request('POST', $uri, $Bulk->toArray());
	}
	
	public function aggregate($Aggregate)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_AGGREGATE, null, null);
		
		return $this->_request('POST', $uri, $Aggregate->toArray());
	}

	public function config($key = null, $value = null)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_CONFIG, null, null);

		return $this->_request('POST', $uri, array( $key => $value ));
	}

	public function cojs()
	{

	}

	public function cojsCommit($token)
	{
		
	}

	public function cojsReject($token)
	{

	}

	/**
	 * Perform an action (e.g. upgrade/downgrade, cancel, void, set payment method, etc.)
	 * 
	 * @param string $type
	 * @param integer $id
	 * @param string $action
	 * @param array $data 
	 * @return array
	 */
	public function action($type, $id, $action, $data = array())
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_ACTION, $id, $type);

		if (is_object($data))
		{
			$data = $data->toArray();
		}

		$uri .= '?action=' . $action;

		return $this->_request('POST', $uri, $data);
	}

	public function create($Object)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_CREATE, null, $Object);
		
		/*
		print('[');
		print_r($Object);
		print(']');
		*/

		return $this->_request('POST', $uri, $Object->toArray());
	}
	
	public function modify($id, $Object)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_MODIFY, $id, $Object);

		return $this->_request('PUT', $uri, $Object->toArray());
	}
	
	public function find($type, $where = array(), $sort = array(), $offset = 0, $limit = null)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_FIND, null, $type);

		$uri .= '?_dummy=1';

		// WHERE 
		if (is_array($where) and 
			count($where))
		{
			foreach ($where as $key => $value)
			{
				$where[$key] = urlencode($value);
			}
		}

		$uri .= '&where=' . implode(',', $where);

		// ORDER 
		if (is_array($sort) and 
			count($sort))
		{
			foreach ($sort as $key => $value)
			{
				$sort[$key] = urlencode($value);
			}
		}

		$uri .= '&order=' . implode(',', $sort);

		if ($offset or $limit)
		{
			$uri .= '&offset=' . ((int) $offset) . '&limit=' . ((int) $limit);
		}

		$resp = $this->_request('GET', $uri);

		if (!$this->isError($resp))
		{
			//print_r($resp);
			//print("\n\n\n\n");

			$class = $this->typeToClass($type);

			// Let's try to transform the array we got back into a list of objects
			foreach ($resp->response as $key => $obj)
			{
				$resp->response[$key] = $this->_createObject($class, $obj);
			}
		}

		return $resp;
	}

	public function delete($type, $id)
	{
		$uri = $this->_map(ChargeOverAPI::METHOD_DELETE, $id, $type);

		return $this->_request('DELETE', $uri);
	}

	public function findById($type, $id)
	{
		if (!$id)
		{
			return $this->_error('You must provide a valid id value to findById($type, $id)');
		}

		$uri = $this->_map(ChargeOverAPI::METHOD_FIND, $id, $type);

		$resp = $this->_request('GET', $uri);

		if (!$this->isError($resp))
		{
			$class = $this->typeToClass($type);

			$resp->response = $this->_createObject($class, $resp->response);
		}

		return $resp;
	}

	protected function _createObject($class, $arr_or_obj)
	{
		if (is_object($arr_or_obj))
		{
			$arr_or_obj = get_object_vars($arr_or_obj);
		}

		// Find any children
		foreach ($arr_or_obj as $key => $value)
		{
			if (is_object($value))
			{
				$value = get_object_vars($value);
			}

			if (is_array($value))
			{
				// This is a child
				switch ($key)
				{
					case 'line_items':
						$sclass = 'ChargeOverAPI_Object_LineItem';
						$is_list = true;
						break;
					case 'tierset':
						$sclass = 'ChargeOverAPI_Object_Tierset';
						$is_list = false;
						break;
					case 'tiers':
						$sclass = 'ChargeOverAPI_Object_Tier';
						$is_list = true;
						break;
					default:
						$sclass = null;
						$is_list = false;
						break;
				}

				if ($sclass)
				{
					if ($is_list)
					{
						foreach ($value as $skey => $obj)
						{
							$arr_or_obj[$key][$skey] = $this->_createObject($sclass, $obj);
						}
					}
					else
					{
						$arr_or_obj[$key] = $this->_createObject($sclass, $value);
					}
				}

				/*
				foreach ($value as $skey => $obj)
				{
					if ($sclass)
					{
						if (is_object($obj))
						{
							$obj = get_object_vars($obj);
						}

						$arr[$key][$skey] = new $sclass($obj);
					}
				}
				*/
			}
			else
			{

			}
		}

		return new $class($arr_or_obj);
	}
}