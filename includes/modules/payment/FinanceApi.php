<?php
/**
 * Created by PhpStorm.
 * User: freddie.line
 * Date: 2019-03-19
 * Time: 11:33
 */

use \GuzzleHttp\Client as Guzzle;

class FinanceApi
{
    private $_sdk;
    /**
     * get all finance plans
     */
    public function getAllFinancePlansFromSDK(){

        // set api key variable
        if(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY === "MODULE_PAYMENT_FINANCEPAYMENT_APIKEY"){
            $apiKey = '';
        }
        else{
            $apiKey = MODULE_PAYMENT_FINANCEPAYMENT_APIKEY;
        }

        // get environment from api key
        $env = $this->environments( $apiKey );

        // create new client
        $client = new \GuzzleHttp\Client();

        // creat client wrapper
        $httpClientWrapper = new \Divido\MerchantSDK\HttpClient\HttpClientWrapper(
            new \Divido\MerchantSDKGuzzle6\GuzzleAdapter($client),
            \Divido\MerchantSDK\Environment::CONFIGURATION[$env]['base_uri'],
            $apiKey
        );

        // create ner sdk
        $this->_sdk = new \Divido\MerchantSDK\Client( $httpClientWrapper, $env);

        // Set any request options.
        $requestOptions = (new \Divido\MerchantSDK\Handlers\ApiRequestOptions());

        // Retrieve all finance plans for the merchant.
        $plans = $this->_sdk->getAllPlans($requestOptions);


        return $plans->getResources();
    }

    /**
     * @return mixed
     */
    public function createAnApplication($request_data){

        // Create an appication model with the application data.
        $application = (new \Divido\MerchantSDK\Models\Application())
            ->withCountryId($request_data['country'])
            ->withCurrencyId($request_data['currency'])
            ->withLanguageId('en')
            ->withFinancePlanId($request_data['finance'])
            ->withApplicants([
                $request_data['customer']
            ])
            ->withOrderItems($request_data['products'])
            ->withDepositAmount($request_data['deposit_amount'])
            ->withFinalisationRequired( false )
            ->withMerchantReference(strval($request_data['metadata']['order_id']))
            ->withUrls([
                'merchant_redirect_url' => $request_data['redirect_url'],
                'merchant_checkout_url' => $request_data['checkout_url'],
                'merchant_response_url' =>$request_data['response_url'],
            ])
            ->withMetadata($request_data['metadata']);

        // Note: If creating an appliclation (credit request) on a merchant with a shared secret, you will have to pass in a correct hmac
        $response = $this->_sdk->applications()->createApplication($application, [],  ['Content-Type' => 'application/json']);
        $application_response_body =  $response->getBody()->getContents();

        $decode                    = json_decode( $application_response_body );
        $result_id                 = $decode->data->id;

        return
            array(
                'result_id' => $result_id ,
                'redirect_url' => $decode->data->urls->application_url
            );
    }


    /**
     * activate application
     */
    public function activateApplicationWithSDK( $request ){

        // First get the application you wish to create an activation for.
        $application = (new \Divido\MerchantSDK\Models\Application())
            ->withId($request['application_id']);

        // Create a new application activation model.
        $applicationActivation = (new \Divido\MerchantSDK\Models\ApplicationActivation())
            ->withAmount($request['amount'])
            ->withReference('reference')
            ->withComment('order was delivered to the customer')
            ->withOrderItems($request['items'])
            ->withDeliveryMethod($request['delivery_method'])
            ->withTrackingNumber($request['tracking_number']);

        // Create a new activation for the application.
        $response = $this->_sdk->applicationActivations()->createApplicationActivation($application, $applicationActivation);


        return $response->getBody()->getContents();
    }

    public function check(){
        return true;
    }

    /**
     * Define environment function
     *
     *  @param [string] $key   - The Divido API key.
     */
    function environments( $key ) {
        $array       = explode( '_', $key );
        $environment = strtoupper( $array[0] );
        switch ($environment) {
            case 'LIVE':
                return constant( 'Divido\MerchantSDK\Environment::' . $environment );
                break;

            case 'SANDBOX':
                return constant( "Divido\MerchantSDK\Environment::$environment" );
                break;

            default:
                return constant( "Divido\MerchantSDK\Environment::SANDBOX" );
                break;
        }

    }

    function keys(){

    }

    public function getFinanceEnv(){

        // set api key variable
        if(MODULE_PAYMENT_FINANCEPAYMENT_APIKEY === "MODULE_PAYMENT_FINANCEPAYMENT_APIKEY"){
            $api_key = '';
        }
        else{
            $api_key = MODULE_PAYMENT_FINANCEPAYMENT_APIKEY;
        }

        if (!$api_key) {
            return array();
        }

        $client = new Guzzle;
        $env = $this->environments($api_key);

        $httpClientWrapper = new \Divido\MerchantSDK\HttpClient\HttpClientWrapper(
            new \Divido\MerchantSDKGuzzle6\GuzzleAdapter($client),
            \Divido\MerchantSDK\Environment::CONFIGURATION[$env]['base_uri'],
            $api_key
        );

        $sdk  = new \Divido\MerchantSDK\Client( $httpClientWrapper, $env );


        $response = $sdk->platformEnvironments()->getPlatformEnvironment();
        $finance_env = $response->getBody()->getContents();
        $decoded =json_decode($finance_env);

        return $decoded->data->environment;


    }




}
