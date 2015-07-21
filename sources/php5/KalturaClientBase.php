<?php
// ===================================================================================================
//                           _  __     _ _
//                          | |/ /__ _| | |_ _  _ _ _ __ _
//                          | ' </ _` | |  _| || | '_/ _` |
//                          |_|\_\__,_|_|\__|\_,_|_| \__,_|
//
// This file is part of the Kaltura Collaborative Media Suite which allows users
// to do with audio, video, and animation what Wiki platfroms allow them to do with
// text.
//
// Copyright (C) 2006-2011  Kaltura Inc.
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// @ignore
// ===================================================================================================

/**
 * @package Kaltura
 * @subpackage Client
 */
class MultiRequestSubResult implements ArrayAccess
{
    function __construct($value)
	{
        $this->value = $value;
	}

    function __toString()
	{
        return '{' . $this->value . '}';
	}

    function __get($name)
	{
        return new MultiRequestSubResult($this->value . ':' . $name);
	}

	public function offsetExists($offset)
	{
		return true;
	}

	public function offsetGet($offset)
	{
        return new MultiRequestSubResult($this->value . ':' . $offset);
	}

	public function offsetSet($offset, $value)
	{
	}

	public function offsetUnset($offset)
	{
	}
}

/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@Null
{
	private static $instance;

	private function __construct()
	{

	}

	public static function getInstance()
	{
		if (!isset(self::$instance)) {
			$c = __CLASS__;
			self::$instance = new $c();
		}
		return self::$instance;
	}

	function __toString()
	{
        return '';
	}

}

/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@ClientBase
{
	const KALTURA_SERVICE_FORMAT_KALTURA = 0;
	const KALTURA_SERVICE_FORMAT_JSON = 1;
	const KALTURA_SERVICE_FORMAT_XML  = 2;
	const KALTURA_SERVICE_FORMAT_PHP  = 3;

	// KS V2 constants
	const RANDOM_SIZE = 16;

	const FIELD_EXPIRY =              '_e';
	const FIELD_TYPE =                '_t';
	const FIELD_USER =                '_u';

	const METHOD_POST 	= 'POST';
	const METHOD_GET 	= 'GET';

	/**
	 * @var @PREFIX@Configuration
	 */
	protected $config;

	/**
	 * @var array
	 */
	protected $clientConfiguration = array();

	/**
	 * @var array
	 */
	protected $requestConfiguration = array();
	
	/**
	 * @var int
	 */
	protected $requestFormat = @PREFIX@ClientBase::KALTURA_SERVICE_FORMAT_KALTURA;
	
	/**
	 * @var int
	 */
	protected $responseFormat = @PREFIX@ClientBase::KALTURA_SERVICE_FORMAT_PHP;

	/**
	 * @var boolean
	 */
	private $shouldLog = false;

	/**
	 * @var bool
	 */
	private $isMultiRequest = false;

	/**
	 * @var unknown_type
	 */
	private $callsQueue = array();

	/**
	 * Array of all plugin services
	 *
	 * @var array<@PREFIX@ServiceBase>
	 */
	protected $pluginServices = array();

	/**
	* @var Array of response headers
	*/
	private $responseHeaders = array();

	/**
	 * path to save served results
	 * @var string
	 */
	protected $destinationPath = null;

	/**
	 * return served results without unserializing them
	 * @var boolean
	 */
	protected $returnServedResult = null;

	public function __get($serviceName)
	{
		if(isset($this->pluginServices[$serviceName]))
			return $this->pluginServices[$serviceName];

		return null;
	}

	/**
	 * @PREFIX@ client constructor
	 *
	 * @param @PREFIX@Configuration $config
	 */
	public function __construct(@PREFIX@Configuration $config)
	{
	    $this->config = $config;
	    $this->responseFormat = $config->format;

	    $logger = $this->config->getLogger();
		if ($logger)
		{
			$this->shouldLog = true;
		}

		// load all plugins
		$pluginsFolder = realpath(dirname(__FILE__)) . '/@PREFIX@Plugins';
		if(is_dir($pluginsFolder))
		{
			$dir = dir($pluginsFolder);
			while (false !== $fileName = $dir->read())
			{
				$matches = null;
				if(preg_match('/^([^.]+).php$/', $fileName, $matches))
				{
					require_once("$pluginsFolder/$fileName");

					$pluginClass = $matches[1];
					if(!class_exists($pluginClass) || !in_array('I@PREFIX@ClientPlugin', class_implements($pluginClass)))
						continue;

					$plugin = call_user_func(array($pluginClass, 'get'), $this);
					if(!($plugin instanceof I@PREFIX@ClientPlugin))
						continue;

					$pluginName = $plugin->getName();
					$services = $plugin->getServices();
					foreach($services as $serviceName => $service)
					{
						$service->setClient($this);
						$this->pluginServices[$serviceName] = $service;
					}
				}
			}
		}
	}

	public function setRequestFormat($requestFormat)
	{
		$this->requestFormat = $requestFormat;
	}

	public function setResponseFormat($responseFormat)
	{
		$this->responseFormat = $responseFormat;
	}

	/* Store response headers into array */
	public function readHeader($ch, $string)
	{
		array_push($this->responseHeaders, $string);
		return strlen($string);
	}

	/* Retrive response headers */
	public function getResponseHeaders()
	{
		return $this->responseHeaders;
	}

	public function getServeUrl()
	{
		if (count($this->callsQueue) != 1)
			return null;

		$params = array();
		$files = array();
		$this->log("service url: [" . $this->config->serviceUrl . "]");

		// append the basic params
		$this->addParam($params, "format", $this->responseFormat);

		foreach($this->clientConfiguration as $param => $value)
		{
			$this->addParam($params, $param, $value);
		}

		$call = $this->callsQueue[0];
		$this->resetRequest();

		$params = array_merge($params, $call->params);
		$signature = $this->signature($params);
		$this->addParam($params, "kalsig", $signature);

		$url = $this->config->serviceUrl . "/api_v3/index.php?service={$call->service}&action={$call->action}";
		$url .= '&' . http_build_query($params);
		$this->log("Returned url [$url]");
		return $url;
	}

	public function queueServiceActionCall($service, $action, $params = array(), $files = array())
	{
		foreach($this->requestConfiguration as $param => $value)
		{
			$this->addParam($params, $param, $value);
		}

		$call = new @PREFIX@ServiceActionCall($service, $action, $params, $files);
		$this->callsQueue[] = $call;
	}

	public function queuePathCall($path, $params = array(), $files = array())
	{
		foreach($this->requestConfiguration as $param => $value)
		{
			$this->addParam($params, $param, $value);
		}

		$call = new @PREFIX@PathCall($path, $params, $files);
		$this->callsQueue[] = $call;
	}

	protected function resetRequest()
	{
		$this->destinationPath = null;
		$this->returnServedResult = false;
		$this->isMultiRequest = false;
		$this->callsQueue = array();
		$this->requestFormat = self::KALTURA_SERVICE_FORMAT_KALTURA;
		$this->responseFormat = $this->config->format;
	}

	/**
	 * Call all API service that are in queue
	 *
	 * @return unknown
	 */
	public function doQueue()
	{
		if($this->isMultiRequest && ($this->destinationPath || $this->returnServedResult))
		{
			$this->resetRequest();
			throw new @PREFIX@ClientException("Downloading files is not supported as part of multi-request.", @PREFIX@ClientException::ERROR_DOWNLOAD_IN_MULTIREQUEST);
		}

		if (count($this->callsQueue) == 0)
		{
			$this->resetRequest();
			return null;
		}

		$startTime = microtime(true);

		$params = array();
		$files = array();
		$this->log("service url: [" . $this->config->serviceUrl . "]");

		// append the basic params
		$this->addParam($params, "format", $this->responseFormat);
		$this->addParam($params, "ignoreNull", true);

		foreach($this->clientConfiguration as $param => $value)
		{
			$this->addParam($params, $param, $value);
		}

		$url = $this->config->serviceUrl;
		$call = $this->callsQueue[0];
		$url .= $call->getPath();
		
		if ($this->isMultiRequest)
		{
			$i = 1;
			foreach ($this->callsQueue as $call)
			{
				$callParams = $call->getParamsForMultiRequest($i);
				$callFiles = $call->getFilesForMultiRequest($i);
				$params = array_merge($params, $callParams);
				$files = array_merge($files, $callFiles);
				$i++;
			}
		}
		else
		{
			$params = array_merge($params, $call->params);
			$files = $call->files;
		}

		$signature = $this->signature($params);
		$this->addParam($params, "kalsig", $signature);

		try
		{
			list($postResult, $error) = $this->doHttpRequest($url, $params, $files);
		}
		catch(Exception $e)
		{
			$this->resetRequest();
			throw $e;
		}

		if ($error)
		{
			$this->resetRequest();
			throw new @PREFIX@ClientException($error, @PREFIX@ClientException::ERROR_GENERIC);
		}
		else
		{
			// print server debug info to log
			$serverName = null;
			$serverSession = null;
			foreach ($this->responseHeaders as $curHeader)
			{
				$splittedHeader = explode(':', $curHeader, 2);
				if ($splittedHeader[0] == 'X-Me')
					$serverName = trim($splittedHeader[1]);
				else if ($splittedHeader[0] == 'X-Kaltura-Session')
					$serverSession = trim($splittedHeader[1]);
			}
			if (!is_null($serverName) || !is_null($serverSession))
				$this->log("server: [{$serverName}], session: [{$serverSession}]");

			$this->log("result (serialized): " . $postResult);

			if($this->returnServedResult)
			{
				$result = $postResult;
			}
			elseif($this->destinationPath)
			{
				if(!$postResult)
				{
					$this->resetRequest();
					throw new @PREFIX@ClientException("failed to download file", @PREFIX@ClientException::ERROR_READ_FAILED);
				}
			}
			elseif ($this->responseFormat == self::KALTURA_SERVICE_FORMAT_PHP)
			{
				$result = @unserialize($postResult);

				if ($result === false && serialize(false) !== $postResult)
				{
					$this->resetRequest();
					throw new @PREFIX@ClientException("failed to unserialize server result\n$postResult", @PREFIX@ClientException::ERROR_UNSERIALIZE_FAILED);
				}
				$dump = print_r($result, true);
				$this->log("result (object dump): " . $dump);
			}
			elseif ($this->responseFormat == self::KALTURA_SERVICE_FORMAT_JSON)
			{
				$result = $this->jsObjectToClientObject(json_decode($postResult));
				$dump = print_r($result, true);
				$this->log("result (object dump): " . $dump);
			}
			else
			{
				$this->resetRequest();
				throw new @PREFIX@ClientException("unsupported format: $postResult", @PREFIX@ClientException::ERROR_FORMAT_NOT_SUPPORTED);
			}
		}
		$this->resetRequest();

		$endTime = microtime (true);

		$this->log("execution time for [".$url."]: [" . ($endTime - $startTime) . "]");

		return $result;
	}

	/**
	 * Sign array of parameters
	 *
	 * @param array $params
	 * @return string
	 */
	private function signature($params)
	{
		ksort($params);
		$str = "";
		foreach ($params as $k => $v)
		{
			$str .= $k.$v;
		}
		return md5($str);
	}

	/**
	 * Send http request by using curl (if available) or php stream_context
	 *
	 * @param string $url
	 * @param parameters $params
	 * @return array of result and error
	 */
	protected function doHttpRequest($url, $params = array(), $files = array())
	{
		if (function_exists('curl_init'))
			return $this->doCurl($url, $params, $files);

		if($this->destinationPath || $this->returnServedResult)
			throw new @PREFIX@ClientException("Downloading files is not supported with stream context http request, please use curl.", @PREFIX@ClientException::ERROR_DOWNLOAD_NOT_SUPPORTED);

		return $this->doPostRequest($url, $params, $files);
	}

	/**
	 * Curl HTTP POST Request
	 *
	 * @param string $url
	 * @param array $params
	 * @param array $files
	 * @return array of result and error
	 */
	private function doCurl($url, $params = array(), $files = array())
	{
		$method = $this->config->method;
		$requestHeaders = $this->config->requestHeaders;
		
		if($this->requestFormat == self::KALTURA_SERVICE_FORMAT_KALTURA)
		{
			$params = http_build_query($params, null, "&");
			$this->log("curl: $url&$params");
			
			// Force POST in case we have files
			if(count($files) > 0) {
				$method = self::METHOD_POST;
			}
			// Check for GET and append params to url
			if($method == self::METHOD_GET) {
				$url = $url . '&' . $params;
			}
		}
		if($this->requestFormat == self::KALTURA_SERVICE_FORMAT_JSON)
		{
			$params = $this->jsonEncode($params);
			$this->log("curl: $url");
			$this->log("post: $params");
			$requestHeaders[] = 'Content-Type: application/json';
			$requestHeaders[] = 'Accept: application/json';
		}  
		elseif($this->requestFormat == self::KALTURA_SERVICE_FORMAT_XML)
		{
			$params = $this->xmlEncode($params);
			$this->log("curl: $url");
			$this->log("post: $params");
			$requestHeaders[] = 'Content-Type: text/xml';
			$requestHeaders[] = 'Accept: text/xml';
		}  
		$requestHeaders[] = 'Accept: text/xml'; 
		
		$this->responseHeaders = array();
		$cookies = array();
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		if($method == self::METHOD_POST) {
			curl_setopt($ch, CURLOPT_POST, 1);
			if (count($files) > 0)
			{
                foreach ($files as &$file) {
                    // The usage of the @filename API for file uploading is
                    // deprecated since PHP 5.5. CURLFile must be used instead.
                    if (PHP_VERSION_ID >= 50500) {
                        $file = new \CURLFile($file);
                    } else {
                        $file = "@" . $file; // let curl know its a file
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge($params, $files));
			}
			else
			{
				curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
			}
		}
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
		curl_setopt($ch, CURLOPT_USERAGENT, $this->config->userAgent);
		if (count($files) > 0)
			curl_setopt($ch, CURLOPT_TIMEOUT, 0);
		else
			curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->curlTimeout);

		if ($this->config->startZendDebuggerSession === true)
		{
			$zendDebuggerParams = $this->getZendDebuggerParams($url);
		 	$cookies = array_merge($cookies, $zendDebuggerParams);
		}

		if (count($cookies) > 0)
		{
			$cookiesStr = http_build_query($cookies, null, '; ');
			curl_setopt($ch, CURLOPT_COOKIE, $cookiesStr);
		}

		if (isset($this->config->proxyHost)) {
			curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
			curl_setopt($ch, CURLOPT_PROXY, $this->config->proxyHost);
			if (isset($this->config->proxyPort)) {
				curl_setopt($ch, CURLOPT_PROXYPORT, $this->config->proxyPort);
			}
			if (isset($this->config->proxyUser)) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->config->proxyUser.':'.$this->config->proxyPassword);
			}
			if (isset($this->config->proxyType) && $this->config->proxyType === 'SOCKS5') {
				curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
			}
		}

		// Set SSL verification
		if(!$this->getConfig()->verifySSL)
		{
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		}
		elseif($this->getConfig()->sslCertificatePath)
		{
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_CAINFO, $this->getConfig()->sslCertificatePath);
		}

		// Set custom headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);

		// Save response headers
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'readHeader') );

		$destinationResource = null;
		if($this->destinationPath)
		{
			$destinationResource = fopen($this->destinationPath, "wb");
			curl_setopt($ch, CURLOPT_FILE, $destinationResource);
		}
		else
		{
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		$result = curl_exec($ch);

		if($destinationResource)
			fclose($destinationResource);

		$curlError = curl_error($ch);
		curl_close($ch);
		return array($result, $curlError);
	}

	/**
	 * HTTP stream context request
	 *
	 * @param string $url
	 * @param array $params
	 * @return array of result and error
	 */
	private function doPostRequest($url, $params = array(), $files = array())
	{
		if (count($files) > 0)
			throw new @PREFIX@ClientException("Uploading files is not supported with stream context http request, please use curl.", @PREFIX@ClientException::ERROR_UPLOAD_NOT_SUPPORTED);

		$formattedData = http_build_query($params , "", "&");
		$this->log("post: $url&$formattedData");

		$params = array('http' => array(
					"method" => "POST",
					"User-Agent: " . $this->config->userAgent . "\r\n".
					"Accept-language: en\r\n".
					"Content-type: application/x-www-form-urlencoded\r\n",
					"content" => $formattedData
		          ));

		if (isset($this->config->proxyType) && $this->config->proxyType === 'SOCKS5') {
			throw new @PREFIX@ClientException("Cannot use SOCKS5 without curl installed.", @PREFIX@ClientException::ERROR_CONNECTION_FAILED);
		}
		if (isset($this->config->proxyHost)) {
			$proxyhost = 'tcp://' . $this->config->proxyHost;
			if (isset($this->config->proxyPort)) {
				$proxyhost = $proxyhost . ":" . $this->config->proxyPort;
			}
			$params['http']['proxy'] = $proxyhost;
			$params['http']['request_fulluri'] = true;
			if (isset($this->config->proxyUser)) {
				$auth = base64_encode($this->config->proxyUser.':'.$this->config->proxyPassword);
				$params['http']['header'] = 'Proxy-Authorization: Basic ' . $auth;
			}
		}

		$ctx = stream_context_create($params);
		$fp = @fopen($url, 'rb', false, $ctx);
		if (!$fp) {
			$phpErrorMsg = "";
			throw new @PREFIX@ClientException("Problem with $url, $phpErrorMsg", @PREFIX@ClientException::ERROR_CONNECTION_FAILED);
		}
		$response = @stream_get_contents($fp);
		if ($response === false) {
		   throw new @PREFIX@ClientException("Problem reading data from $url, $phpErrorMsg", @PREFIX@ClientException::ERROR_READ_FAILED);
		}
		return array($response, '');
	}

	/**
	 * @param boolean $returnServedResult
	 */
	public function setReturnServedResult($returnServedResult)
	{
		$this->returnServedResult = $returnServedResult;
	}

	/**
	 * @return boolean
	 */
	public function getReturnServedResult()
	{
		return $this->returnServedResult;
	}

	/**
	 * @param string $destinationPath
	 */
	public function setDestinationPath($destinationPath)
	{
		$this->destinationPath = $destinationPath;
	}

	/**
	 * @return string
	 */
	public function getDestinationPath()
	{
		return $this->destinationPath;
	}

	/**
	 * @return @PREFIX@Configuration
	 */
	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * @param @PREFIX@Configuration $config
	 */
	public function setConfig(@PREFIX@Configuration $config)
	{
		$this->config = $config;
		$this->responseFormat = $config->format;

		$logger = $this->config->getLogger();
		if ($logger instanceof I@PREFIX@Logger)
		{
			$this->shouldLog = true;
		}
	}

	public function setClientConfiguration(@PREFIX@ClientConfiguration $configuration)
	{
		$params = get_class_vars('@PREFIX@ClientConfiguration');
		foreach($params as $param)
		{
			if(is_null($configuration->$param))
			{
				if(isset($this->clientConfiguration[$param]))
				{
					unset($this->clientConfiguration[$param]);
				}
			}
			else
			{
				$this->clientConfiguration[$param] = $configuration->$param;
			}
		}
	}

	public function setRequestConfiguration(@PREFIX@RequestConfiguration $configuration)
	{
		$params = get_class_vars('@PREFIX@RequestConfiguration');
		foreach($params as $param)
		{
			if(is_null($configuration->$param))
			{
				if(isset($this->requestConfiguration[$param]))
				{
					unset($this->requestConfiguration[$param]);
				}
			}
			else
			{
				$this->requestConfiguration[$param] = $configuration->$param;
			}
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public function jsObjectToClientObject($value)
	{
		if(is_array($value))
		{
			foreach($value as &$item)
			{
				$item = $this->jsObjectToClientObject($item);
			}
		}
		
		if(is_object($value))
		{
			if(isset($value->message) && isset($value->code))
			{
				throw new @PREFIX@Exception($value->message, $value->code, $value->args);
			}
			
			if(!isset($value->objectType))
			{
				throw new @PREFIX@ClientException("Response format not supported - objectType is required for all objects", @PREFIX@ClientException::ERROR_FORMAT_NOT_SUPPORTED);
			}
			
			$objectType = $value->objectType;
			$object = new $objectType();
			$attributes = get_object_vars($value);
			foreach($attributes as $attribute => $attributeValue)
			{
				if($attribute === 'objectType')
				{
					continue;
				}
				
				$object->$attribute = $this->jsObjectToClientObject($attributeValue);
			}
			
			$value = $object;
		}
		
		return $value;
	}

	/**
	 * Encodes objects
	 * @param mixed $value
	 * @return string
	 */
	public function jsonEncode($value)
	{
		return json_encode($this->unsetNull($value));
	}

	protected function unsetNull($object)
	{
		if(!is_array($object) && !is_object($object))
			return $object;
		
		$array = (array) $object;
		foreach($array as $key => $value)
		{
			if(is_null($value))
			{
				unset($array[$key]);
			}
			else
			{
				$array[$key] = $this->unsetNull($value);
			}
		}

		if(is_object($object))
			$array['objectType'] = get_class($object);
			
		return $array;
	}

	/**
	 * Add parameter to array of parameters that is passed by reference
	 *
	 * @param arrat $params
	 * @param string $paramName
	 * @param string $paramValue
	 */
	public function addParam(&$params, $paramName, $paramValue)
	{
		if ($paramValue === null)
			return;

		if ($paramValue instanceof @PREFIX@Null) {
			$params[$paramName . '__null'] = '';
			return;
		}

		if(is_object($paramValue) && $paramValue instanceof @PREFIX@ObjectBase)
		{
			$this->addParam($params, "$paramName:objectType", get_class($paramValue));
		    foreach($paramValue as $prop => $val)
				$this->addParam($params, "$paramName:$prop", $val);

			return;
		}

		if(!is_array($paramValue))
		{
			$params[$paramName] = (string)$paramValue;
			return;
		}

		if ($paramValue)
		{
			foreach($paramValue as $subParamName => $subParamValue)
				$this->addParam($params, "$paramName:$subParamName", $subParamValue);
		}
		else
		{
			$this->addParam($params, "$paramName:-", "");
		}
	}

	/**
	 * Validate the result object and throw exception if its an error
	 *
	 * @param object $resultObject
	 */
	public function throwExceptionIfError($resultObject)
	{
		if ($this->isError($resultObject))
		{
			throw new @PREFIX@Exception($resultObject["message"], $resultObject["code"], $resultObject["args"]);
		}
	}

	/**
	 * Checks whether the result object is an error
	 *
	 * @param object $resultObject
	 */
	public function isError($resultObject)
	{
		return (is_array($resultObject) && isset($resultObject["message"]) && isset($resultObject["code"]));
	}

	/**
	 * Validate that the passed object type is of the expected type
	 *
	 * @param any $resultObject
	 * @param string $objectType
	 */
	public function validateObjectType($resultObject, $objectType)
	{
		$knownNativeTypes = array("boolean", "integer", "double", "string");
		if (is_null($resultObject) ||
			( in_array(gettype($resultObject) ,$knownNativeTypes) &&
			  in_array($objectType, $knownNativeTypes) ) )
		{
			return;// we do not check native simple types
		}
		else if ( is_object($resultObject) )
		{
			if (!($resultObject instanceof $objectType))
			{
				throw new @PREFIX@ClientException("Invalid object type - not instance of $objectType", @PREFIX@ClientException::ERROR_INVALID_OBJECT_TYPE);
			}
		}
		else if(is_subclass_of($objectType, '@PREFIX@EnumBase'))
		{
			$enum = new ReflectionClass($objectType);
			$values = array_map('strval', $enum->getConstants());
			if(!in_array($resultObject, $values))
			{
				throw new @PREFIX@ClientException("Invalid enum value", @PREFIX@ClientException::ERROR_INVALID_ENUM_VALUE);
			}
		}
		else if(gettype($resultObject) !== $objectType)
		{
			throw new @PREFIX@ClientException("Invalid object type", @PREFIX@ClientException::ERROR_INVALID_OBJECT_TYPE);
		}
	}


	public function startMultiRequest()
	{
		$this->isMultiRequest = true;
	}

	public function doMultiRequest()
	{
		return $this->doQueue();
	}

	public function isMultiRequest()
	{
		return $this->isMultiRequest;
	}

	public function getMultiRequestQueueSize()
	{
		return count($this->callsQueue);
	}

    public function getMultiRequestResult()
	{
        return new MultiRequestSubResult($this->getMultiRequestQueueSize() . ':result');
	}

	/**
	 * @param string $msg
	 */
	protected function log($msg)
	{
		if ($this->shouldLog)
			$this->config->getLogger()->log($msg);
	}

	/**
	 * Return a list of parameter used to a new start debug on the destination server api
	 * @link http://kb.zend.com/index.php?View=entry&EntryID=434
	 * @param $url
	 */
	protected function getZendDebuggerParams($url)
	{
		$params = array();
		$passThruParams = array('debug_host',
			'debug_fastfile',
			'debug_port',
			'start_debug',
			'send_debug_header',
			'send_sess_end',
			'debug_jit',
			'debug_stop',
			'use_remote');

		foreach($passThruParams as $param)
		{
			if (isset($_COOKIE[$param]))
				$params[$param] = $_COOKIE[$param];
		}

		$params['original_url'] = $url;
		$params['debug_session_id'] = microtime(true); // to create a new debug session

		return $params;
	}

	public function generateSession($adminSecretForSigning, $userId, $type, $partnerId, $expiry = 86400, $privileges = '')
	{
		$rand = rand(0, 32000);
		$expiry = time()+$expiry;
		$fields = array (
			$partnerId ,
			$partnerId ,
			$expiry ,
			$type,
			$rand ,
			$userId ,
			$privileges
		);
		$info = implode ( ";" , $fields );

		$signature = $this->hash ( $adminSecretForSigning , $info );
		$strToHash =  $signature . "|" . $info ;
		$encoded_str = base64_encode( $strToHash );

		return $encoded_str;
	}

	public static function generateSessionV2($adminSecretForSigning, $userId, $type, $partnerId, $expiry, $privileges)
	{
		// build fields array
		$fields = array();
		foreach (explode(',', $privileges) as $privilege)
		{
			$privilege = trim($privilege);
			if (!$privilege)
				continue;
			if ($privilege == '*')
				$privilege = 'all:*';
			$splittedPrivilege = explode(':', $privilege, 2);
			if (count($splittedPrivilege) > 1)
				$fields[$splittedPrivilege[0]] = $splittedPrivilege[1];
			else
				$fields[$splittedPrivilege[0]] = '';
		}
		$fields[self::FIELD_EXPIRY] = time() + $expiry;
		$fields[self::FIELD_TYPE] = $type;
		$fields[self::FIELD_USER] = $userId;

		// build fields string
		$fieldsStr = http_build_query($fields, '', '&');
		$rand = '';
		for ($i = 0; $i < self::RANDOM_SIZE; $i++)
			$rand .= chr(rand(0, 0xff));
		$fieldsStr = $rand . $fieldsStr;
		$fieldsStr = sha1($fieldsStr, true) . $fieldsStr;

		// encrypt and encode
		$encryptedFields = self::aesEncrypt($adminSecretForSigning, $fieldsStr);
		$decodedKs = "v2|{$partnerId}|" . $encryptedFields;
		return str_replace(array('+', '/'), array('-', '_'), base64_encode($decodedKs));
	}

	protected static function aesEncrypt($key, $message)
	{
		return mcrypt_encrypt(
			MCRYPT_RIJNDAEL_128,
			substr(sha1($key, true), 0, 16),
			$message,
			MCRYPT_MODE_CBC,
			str_repeat("\0", 16)	// no need for an IV since we add a random string to the message anyway
		);
	}

	private function hash ( $salt , $str )
	{
		return sha1($salt.$str);
	}

	/**
	 * @return @PREFIX@Null
	 */
	public static function get@PREFIX@NullValue()
	{

        return @PREFIX@Null::getInstance();
	}

}

/**
 * @package Kaltura
 * @subpackage Client
 */
interface I@PREFIX@ClientPlugin
{
	/**
	 * @return @PREFIX@ClientPlugin
	 */
	public static function get(@PREFIX@Client $client);

	/**
	 * @return array<@PREFIX@ServiceBase>
	 */
	public function getServices();

	/**
	 * @return string
	 */
	public function getName();
}

/**
 * @package Kaltura
 * @subpackage Client
 */
abstract class @PREFIX@ClientPlugin implements I@PREFIX@ClientPlugin
{
	protected function __construct(@PREFIX@Client $client)
	{

	}
}

/**
 * @package Kaltura
 * @subpackage Client
 */
abstract class @PREFIX@Call
{
	/**
	 * @var array
	 */
	public $params;

	/**
	 * @var array
	 */
	public $files;

	/**
	 * Contruct new @PREFIX@ call, if params array contain sub arrays (for objects), it will be flattened
	 *
	 * @param array $params
	 * @param array $files
	 */
	public function __construct($params = array(), $files = array())
	{
		$this->params = $this->parseParams($params);
		$this->files = $files;
	}
	
	/**
	 * @return string
	 */
	abstract public function getPath();

	/**
	 * Parse params array and sub arrays (for objects)
	 *
	 * @param array $params
	 */
	public function parseParams(array $params)
	{
		$newParams = array();
		foreach($params as $key => $val)
		{
			if (is_array($val))
			{
				$newParams[$key] = $this->parseParams($val);
			}
			else
			{
				$newParams[$key] = $val;
			}
		}
		return $newParams;
	}

	/**
	 * Return the parameters for a multi request
	 *
	 * @param int $multiRequestIndex
	 */
	public function getParamsForMultiRequest($multiRequestIndex)
	{
		$multiRequestParams = array();
		foreach($this->params as $key => $val)
		{
			$multiRequestParams[$multiRequestIndex.":".$key] = $val;
		}
		return $multiRequestParams;
	}

	/**
	 * Return the parameters for a multi request
	 *
	 * @param int $multiRequestIndex
	 */
	public function getFilesForMultiRequest($multiRequestIndex)
	{
		$multiRequestParams = array();
		foreach($this->files as $key => $val)
		{
			$multiRequestParams[$multiRequestIndex.":".$key] = $val;
		}
		return $multiRequestParams;
	}
}


/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@ServiceActionCall extends @PREFIX@Call
{
	/**
	 * @var string
	 */
	public $service;

	/**
	 * @var string
	 */
	public $action;

	/**
	 * Contruct new @PREFIX@ service action call, if params array contain sub arrays (for objects), it will be flattened
	 *
	 * @param string $service
	 * @param string $action
	 * @param array $params
	 * @param array $files
	 */
	public function __construct($service, $action, $params = array(), $files = array())
	{
		parent::__construct($params, $files);
		
		$this->service = $service;
		$this->action = $action;
	}

	public function getPath()
	{
		$path = '/api_v3/index.php?service=';
		if ($this->isMultiRequest)
		{
			$path .= 'multirequest';
		}
		else
		{
			$path .= $call->service . '&action=' . $call->action;
		}
		return $path;
	}
			
	/**
	 * Return the parameters for a multi request
	 *
	 * @param int $multiRequestIndex
	 */
	public function getParamsForMultiRequest($multiRequestIndex)
	{
		$multiRequestParams = parent::getParamsForMultiRequest($multiRequestIndex);
		$multiRequestParams[$multiRequestIndex.":service"] = $this->service;
		$multiRequestParams[$multiRequestIndex.":action"] = $this->action;
		return $multiRequestParams;
	}
}


/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@PathCall extends @PREFIX@Call
{
	/**
	 * @var string
	 */
	public $path;

	/**
	 * Contruct new @PREFIX@ service action call, if params array contain sub arrays (for objects), it will be flattened
	 *
	 * @param string $path
	 * @param array $params
	 * @param array $files
	 */
	public function __construct($path, $params = array(), $files = array())
	{
		$this->path = $path;
		$this->params = $this->parseParams($params);
		$this->files = $files;
	}
	
	public function getPath()
	{
		// TODO support multi-request
		return $this->path;
	}
}

/**
 * Abstract base class for all client services
 *
 * @package Kaltura
 * @subpackage Client
 */
abstract class @PREFIX@ServiceBase
{
	/**
	 * @var @PREFIX@Client
	 */
	protected $client;

	/**
	 * Initialize the service keeping reference to the @PREFIX@Client
	 *
	 * @param @PREFIX@Client $client
	 */
	public function __construct(@PREFIX@Client $client = null)
	{
		$this->client = $client;
	}

	/**
	 * @param @PREFIX@Client $client
	 */
	public function setClient(@PREFIX@Client $client)
	{
		$this->client = $client;
	}
}

/**
 * Abstract base class for all client enums
 *
 * @package Kaltura
 * @subpackage Client
 */
abstract class @PREFIX@EnumBase
{
}

/**
 * Abstract base class for all client objects
 *
 * @package Kaltura
 * @subpackage Client
 */
abstract class @PREFIX@ObjectBase
{
	/**
	 * @var array
	 */
	public $relatedObjects;

	public function __construct($params = array())
	{
		foreach ($params as $key => $value)
		{
			if (!property_exists($this, $key))
				throw new @PREFIX@ClientException("property [{$key}] does not exist on object [".get_class($this)."]", @PREFIX@ClientException::ERROR_INVALID_OBJECT_FIELD);
			$this->$key = $value;
		}
	}

	protected function addIfNotNull(&$params, $paramName, $paramValue)
	{
		if ($paramValue !== null)
		{
			if($paramValue instanceof @PREFIX@ObjectBase)
			{
				$params[$paramName] = $paramValue->toParams();
			}
			else
			{
				$params[$paramName] = $paramValue;
			}
		}
	}

	public function toParams()
	{
		$params = array();
		$params["objectType"] = get_class($this);
	    foreach($this as $prop => $val)
		{
			$this->addIfNotNull($params, $prop, $val);
		}
		return $params;
	}
}

/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@Exception extends Exception
{
	private $arguments;

    public function __construct($message, $code, $arguments)
    {
    	$this->code = $code;
    	$this->arguments = $arguments;

		parent::__construct($message);
    }

	/**
	 * @return array
	 */
	public function getArguments()
	{
		return $this->arguments;
	}

	/**
	 * @return string
	 */
	public function getArgument($argument)
	{
		if($this->arguments && isset($this->arguments[$argument]))
		{
			return $this->arguments[$argument];
		}

		return null;
	}
}

/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@ClientException extends Exception
{
	const ERROR_GENERIC = -1;
	const ERROR_UNSERIALIZE_FAILED = -2;
	const ERROR_FORMAT_NOT_SUPPORTED = -3;
	const ERROR_UPLOAD_NOT_SUPPORTED = -4;
	const ERROR_CONNECTION_FAILED = -5;
	const ERROR_READ_FAILED = -6;
	const ERROR_INVALID_PARTNER_ID = -7;
	const ERROR_INVALID_OBJECT_TYPE = -8;
	const ERROR_INVALID_OBJECT_FIELD = -9;
	const ERROR_DOWNLOAD_NOT_SUPPORTED = -10;
	const ERROR_DOWNLOAD_IN_MULTIREQUEST = -11;
	const ERROR_ACTION_IN_MULTIREQUEST = -12;
	const ERROR_INVALID_ENUM_VALUE = -13;
}

/**
 * @package Kaltura
 * @subpackage Client
 */
class @PREFIX@Configuration
{
	private $logger;

	public $serviceUrl    				= "http://www.kaltura.com/";
	public $format        				= @PREFIX@ClientBase::KALTURA_SERVICE_FORMAT_PHP;
	public $curlTimeout   				= 120;
	public $userAgent					= '';
	public $startZendDebuggerSession 	= false;
	public $proxyHost                   = null;
	public $proxyPort                   = null;
	public $proxyType                   = 'HTTP';
	public $proxyUser                   = null;
	public $proxyPassword               = '';
	public $verifySSL 					= true;
	public $sslCertificatePath			= null;
	public $requestHeaders				= array();
	public $method						= @PREFIX@ClientBase::METHOD_POST;

	/**
	 * Set logger to get kaltura client debug logs
	 *
	 * @param I@PREFIX@Logger $log
	 */
	public function setLogger(I@PREFIX@Logger $log)
	{
		$this->logger = $log;
	}

	/**
	 * Gets the logger (Internal client use)
	 *
	 * @return I@PREFIX@Logger
	 */
	public function getLogger()
	{
		return $this->logger;
	}
}

/**
 * Implement to get @PREFIX@ Client logs
 *
 * @package Kaltura
 * @subpackage Client
 */
interface I@PREFIX@Logger
{
	function log($msg);
}

class @PREFIX@ParseUtils 
{
	public static function unmarshalSimpleType(\SimpleXMLElement $xml) 
	{
		return "$xml";
	}
	
	public static function unmarshalObject(\SimpleXMLElement $xml, $fallbackType = null) 
	{
		$objectType = reset($xml->objectType);
		$type = @PREFIX@TypeMap::getZendType($objectType);
		if(!class_exists($type)) {
			$type = @PREFIX@TypeMap::getZendType($fallbackType);
			if(!class_exists($type))
				throw new @PREFIX@ClientException("Invalid object type class [$type] of Kaltura type [$objectType]", @PREFIX@ClientException::ERROR_INVALID_OBJECT_TYPE);
		}
			
		return new $type($xml);
	}
	
	public static function unmarshalArray(\SimpleXMLElement $xml, $fallbackType = null)
	{
		$xmls = $xml->children();
		$ret = array();
		foreach($xmls as $xml)
			$ret[] = self::unmarshalObject($xml, $fallbackType);
			
		return $ret;
	}
	
	public static function unmarshalMap(\SimpleXMLElement $xml, $fallbackType = null)
	{
		$xmls = $xml->children();
		$ret = array();
		foreach($xmls as $xml)
			$ret[strval($xml->itemKey)] = self::unmarshalObject($xml, $fallbackType);
			
		return $ret;
	}

	public static function checkIfError(\SimpleXMLElement $xml, $throwException = true) 
	{
		if(($xml->error) && (count($xml->children()) == 1))
		{
			$code = "{$xml->error->code}";
			$message = "{$xml->error->message}";
			$arguments = self::unmarshalArray($xml->error->args, 'KalturaApiExceptionArg');
			if($throwException)
				throw new @PREFIX@Exception($message, $code, $arguments);
			else 
				return new @PREFIX@Exception($message, $code, $arguments);
		}
	}
}

