<?php

namespace Modules\PayfastGateway\Entities;

use App\Models\Gateways\Gateway;
use App\Models\Gateways\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\Request;
use Omnipay\Common\GatewayInterface;

/**
 * Class ExampleGateway
 *
 * ExampleGateway implements the PaymentGatewayInterface, defining the contract for payment gateways within the system.
 * It provides methods to handle payments, receive responses from the payment gateway, process refunds, configure the gateway,
 * fetch configuration, and check subscriptions.
 *
 * @package Modules\ExampleGateway\Entities
 */
class PayfastGateway implements PaymentGatewayInterface
{

    /**
     * The method is responsible for preparing and processing the payment get the gateway and payment objects
     *
     * @param Gateway $gateway
     * @param Payment $payment
     */
    public static function processGateway(Gateway $gateway, Payment $payment)
    {
        if(!$gateway->config()['merchant_id'] || !$gateway->config()['merchant_key']) {
            throw new \Exception('Merchant ID and Merchant Key are required');
        }

        $data = [
            // Merchant details
            'merchant_id' => $gateway->config()['merchant_id'],
            'merchant_key' => $gateway->config()['merchant_key'],
            'return_url' => route('payment.success', ['payment' => $payment->id]),
            'cancel_url' => route('payment.cancel', ['payment' => $payment->id]),
            'notify_url' => route('payment.return', ['gateway' => self::endpoint()]),
            // Buyer details
            'name_first' => $payment->user->first_name,
            'name_last'  => $payment->user->last_name,
            'email_address'=> $payment->user->email,
            // Transaction details
            'amount' => $payment->amount,
            'item_name' => $payment->description,
            // custom data
            'custom_str1' => $payment->id,
        ];
        
        $signature = self::generateSignature($data);
        $data['signature'] = $signature;

        // update payment with the signature
        $payment->update(['data' => array_merge($payment->data ?? [], ['signature' => $signature])]);
        
        $pfHost = 'www.payfast.co.za';
        $htmlForm = '
        <!DOCTYPE html>
        <html>
        <head>
            <title>Please wait...</title>
        </head>
        <body>
        <h1>Please wait...</h1>
        <form id="payfast-form" action="https://'.$pfHost.'/eng/process" method="post">';

        foreach($data as $name=> $value)
        {
            $htmlForm .= '<input name="'.$name.'" type="hidden" value=\''.$value.'\' />';
        }

        $htmlForm .= '
        </form>
        <script type="text/javascript">
            document.getElementById("payfast-form").submit();
        </script>
        </body>
        </html>
        ';

        return response($htmlForm, 200)->header('Content-Type', 'text/html');
    }

    /**
     * @param array $data
     * @param null $passPhrase
     * @return string
     */
    private static function generateSignature($data, $passPhrase = null) {
        // Create parameter string
        $pfOutput = '';
        foreach( $data as $key => $val ) {
            if($val !== '') {
                $pfOutput .= $key .'='. urlencode( trim( $val ) ) .'&';
            }
        }
        // Remove last ampersand
        $getString = substr( $pfOutput, 0, -1 );
        if( $passPhrase !== null ) {
            $getString .= '&passphrase='. urlencode( trim( $passPhrase ) );
        }

        return md5( $getString );
    } 

    /**
     * Handles the response from the payment gateway. It uses a Request object to receive and handle the response appropriately.
     * endpoint: payment/return/{endpoint_gateway}
     * @param Request $request
     */
    public static function returnGateway(Request $request)
    {
        header('HTTP/1.0 200 OK');
        flush();

        $gateway = Gateway::where('endpoint', self::endpoint())->first();

        if(!$gateway) {
            return response('Gateway not found', 500);
        }

        $payment = Payment::find($request->custom_str1 ?? 'none');

        if(!$payment) {
            return response('Payment not found', 500);
        }

        if($request->get('payment_status', 'none') !== 'COMPLETE') {
            return;
        }

        $pfHost = 'www.payfast.co.za';

        // Posted variables from ITN
        $pfData = $_POST;
        
        // Strip any slashes in data
        foreach( $pfData as $key => $val ) {
            $pfData[$key] = stripslashes( $val );
        }

        // Variable initialization
        $pfParamString = '';
                
        // Convert posted variables to a string
        foreach( $pfData as $key => $val ) {
            if( $key !== 'signature' ) {
                $pfParamString .= $key .'='. urlencode( $val ) .'&';
            } else {
                break;
            }
        }

        $pfParamString = substr( $pfParamString, 0, -1 ); 

        // Verify security signature
        $validSignature = self::pfValidSignature( $pfData, $pfParamString );

        // Verify source IP (If not in debug mode)
        $validHost = self::pfValidIP();

        // Verify data received
        $validAmount = self::pfValidPaymentData($payment->amount, $pfData);

        // Server confirmation
        $validServerConfirmation = self::pfValidServerConfirmation($pfParamString, $pfHost);

        if($validSignature && $validHost && $validAmount && $validServerConfirmation) {
            $payment->completed($request->get('pf_payment_id'));
            return response('Payment successful', 200);
        } else {
            return response('Payment failed', 500);
        }
    }

    private static function pfValidSignature( $pfData, $pfParamString, $pfPassphrase = null ) {
        // Calculate security signature
        if($pfPassphrase === null) {
            $tempParamString = $pfParamString;
        } else {
            $tempParamString = $pfParamString.'&passphrase='.urlencode( $pfPassphrase );
        }
    
        $signature = md5( $tempParamString );
        return ( $pfData['signature'] === $signature );
    }

    private static function pfValidIP() {
        // Variable initialization
        $validHosts = array(
            'www.payfast.co.za',
            'sandbox.payfast.co.za',
            'w1w.payfast.co.za',
            'w2w.payfast.co.za',
            );
    
        $validIps = [];
    
        foreach( $validHosts as $pfHostname ) {
            $ips = gethostbynamel( $pfHostname );
    
            if( $ips !== false )
                $validIps = array_merge( $validIps, $ips );
        }
    
        // Remove duplicates
        $validIps = array_unique( $validIps );
        $referrerIp = gethostbyname(parse_url($_SERVER['HTTP_REFERER'])['host']);
        if( in_array( $referrerIp, $validIps, true ) ) {
            return true;
        }
        return false;
    }

    private static function pfValidPaymentData( $cartTotal, $pfData ) {
        return !(abs((float)$cartTotal - (float)$pfData['amount_gross']) > 0.01);
    } 

    private static function pfValidServerConfirmation( $pfParamString, $pfHost = 'payfast.co.za', $pfProxy = null ) {
        // Use cURL (if available)
        if( in_array( 'curl', get_loaded_extensions(), true ) ) {
            // Variable initialization
            $url = 'https://'. $pfHost .'/eng/query/validate';
    
            // Create default cURL object
            $ch = curl_init();
        
            // Set cURL options - Use curl_setopt for greater PHP compatibility
            // Base settings
            curl_setopt( $ch, CURLOPT_USERAGENT, NULL );  // Set user agent
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );      // Return output as string rather than outputting it
            curl_setopt( $ch, CURLOPT_HEADER, false );             // Don't include header in output
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
            
            // Standard settings
            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_POST, true );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $pfParamString );
            if( !empty( $pfProxy ) )
                curl_setopt( $ch, CURLOPT_PROXY, $pfProxy );
        
            // Execute cURL
            $response = curl_exec( $ch );
            curl_close( $ch );
            if ($response === 'VALID') {
                return true;
            }
        }
        return false;
    } 

    /**
     * Handles refunds. It takes a Payment object and additional data required for processing a refund.
     * An optional method to add user refund support
     *
     * @param Payment $payment
     * @param array $data
     */
    public static function processRefund(Payment $payment, array $data)
    {
        // not required
    }

    /**
     * Defines the configuration for the payment gateway. It returns an array with data defining the gateway driver,
     * type, class, endpoint, refund support, etc.
     *
     * @return array
     */
    public static function drivers(): array
    {
        return [
            'Payfast' => [
                'driver' => 'Payfast',
                'type' => 'once', // subscription
                'class' => 'Modules\PayfastGateway\Entities\PayfastGateway',
                'endpoint' => self::endpoint(),
                'refund_support' => false,
            ]
        ];
    }

    /**
     * Defines the endpoint for the payment gateway. This is an ID used to automatically determine which gateway to use.
     *
     * @return string
     */
    public static function endpoint(): string
    {
        return 'payfast';
    }

    /**
     * Returns an array with the configuration for the payment gateway.
     * These options are displayed for the administrator to configure.
     * You can access them: $gateway->config()
     * @return array
     */
    public static function getConfigMerge(): array
    {
        return [
            'merchant_id' => '',
            'merchant_key' => '',
        ];
    }

    /**
     * Checks the status of a subscription in the payment gateway. If the subscription is active, it returns true; otherwise, it returns false.
     * Do not change this method if you are not using subscriptions
     * @param Gateway $gateway
     * @param $subscriptionId
     * @return bool
     */
    public static function checkSubscription(Gateway $gateway, $subscriptionId): bool
    {
        return false;
    }
}