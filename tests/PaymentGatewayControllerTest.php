<?php

use SilverStripe\Omnipay\PaymentGatewayController;

class PaymentGatewayControllerTest extends PaymentTest
{

    public static $fixture_file = array(
        'payment.yml'
    );

    public function testReturnUrlGeneration()
    {
        $url = PaymentGatewayController::getEndpointUrl('action', "UniqueHashHere12345");
        $this->assertEquals(
            Director::absoluteURL("paymentendpoint/UniqueHashHere12345/action"),
            $url,
            "generated url"
        );
    }

    public function testStaticUrlGeneration()
    {
        $url = PaymentGatewayController::getStaticEndpointUrl('Dummy', 'complete');
        $this->assertEquals('', $url);

        Config::inst()->update('GatewayInfo', 'Dummy', array('use_static_route' => true));
        $url = PaymentGatewayController::getStaticEndpointUrl('Dummy', 'complete');
        $this->assertEquals(Director::absoluteURL("paymentendpoint/gateway/Dummy/complete"), $url);

        $url = PaymentGatewayController::getStaticEndpointUrl('Dummy');
        $this->assertEquals(Director::absoluteURL("paymentendpoint/gateway/Dummy"), $url);
    }

    public function testCompleteEndpoint()
    {
        $this->setMockHttpResponse(
            'paymentexpress/tests/Mock/PxPayCompletePurchaseSuccess.txt'
        );
        //mock the 'result' get variable into the current request
        $this->getHttpRequest()->query->replace(array('result' => 'abc123'));
        //mimic a redirect or request from offsite gateway
        $response = $this->get("paymentendpoint/UNIQUEHASH23q5123tqasdf/complete");
        //redirect works
        $headers = $response->getHeaders();
        $this->assertEquals(
            Director::baseURL()."shop/complete",
            $headers['Location'],
            "redirected to shop/complete"
        );
        $payment = Payment::get()
                        ->filter('Identifier', 'UNIQUEHASH23q5123tqasdf')
                        ->first();
        $this->assertDOSContains(array(
            array('ClassName' => 'PurchaseRequest'),
            array('ClassName' => 'PurchaseRedirectResponse'),
            array('ClassName' => 'CompletePurchaseRequest'),
            array('ClassName' => 'PurchasedResponse')
        ), $payment->Messages());
    }

    public function testNotifyEndpoint()
    {
        $this->setMockHttpResponse(
            'paymentexpress/tests/Mock/PxPayCompletePurchaseSuccess.txt'
        );
        //mock the 'result' get variable into the current request
        $this->getHttpRequest()->query->replace(array('result' => 'abc123'));
        //mimic a redirect or request from offsite gateway
        $response = $this->get("paymentendpoint/UNIQUEHASH23q5123tqasdf/notify");
        //redirect works
        $this->assertNull($response->getHeader('Location'));
        $payment = Payment::get()
                        ->filter('Identifier', 'UNIQUEHASH23q5123tqasdf')
                        ->first();
        $this->assertDOSContains(array(
            array('ClassName' => 'PurchaseRequest'),
            array('ClassName' => 'PurchaseRedirectResponse'),
            array('ClassName' => 'CompletePurchaseRequest'),
            array('ClassName' => 'PurchasedResponse')
        ), $payment->Messages());
    }

    public function testCancelEndpoint()
    {
        // mimic a redirect or request from offsite gateway. The user cancelled the payment
        $response = $this->get("paymentendpoint/UNIQUEHASH23q5123tqasdf/cancel");

        // Should redirect to the cancel/failure url which is being loaded from the fixture
        $headers = $response->getHeaders();
        $this->assertEquals(
            Director::baseURL()."shop/incomplete",
            $headers['Location'],
            "redirected to shop/incomplete"
        );

        $payment = Payment::get()
            ->filter('Identifier', 'UNIQUEHASH23q5123tqasdf')
            ->first();

        $this->assertDOSContains(array(
            array('ClassName' => 'PurchaseRequest'),
            array('ClassName' => 'PurchaseRedirectResponse')
        ), $payment->Messages());

        $this->assertEquals($payment->Status, 'Void', 'Payment should be void');
    }

    public function testInvalidAction()
    {
        // Try to access a valid payment, but bad action
        $response = $this->get("paymentendpoint/UNIQUEHASH23q5123tqasdf/bogus");

        $this->assertEquals($response->getStatusCode(), 404);
    }

    public function testBadReturnURLs()
    {
        $response = $this->get("paymentendpoint/ASDFHSADFunknonwhash/complete/c2hvcC9jb2");
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testInvalidStatus()
    {
        // try to complete a payment that has status "Created"
        $response = $this->get("paymentendpoint/ce3a0b03349078d8e85d1de8ded3f0/complete");
        $this->assertEquals($response->getStatusCode(), 403);
    }

    public function testStaticRoute()
    {
        $staticUrl = 'paymentendpoint/gateway/PaymentExpress_PxPay/complete?id=UNIQUEHASH23q5123tqasdf';
        $response = $this->get($staticUrl);
        // should return 404, because static route isn't enabled
        $this->assertEquals($response->getStatusCode(), 404);

        // Configure gateway to use static route
        Config::inst()->update('GatewayInfo', 'PaymentExpress_PxPay', array('use_static_route' => true));

        $this->setMockHttpResponse(
            'paymentexpress/tests/Mock/PxPayCompletePurchaseSuccess.txt'
        );
        $this->getHttpRequest()->query->replace(array('result' => 'abc123'));
        $response = $this->get($staticUrl);
        // should return 404, because controller won't be able to find a payment
        $this->assertEquals($response->getStatusCode(), 404);

        // Add extension that will find the paymeng from the request params
        PaymentGatewayController::add_extension('PaymentGatewayControllerTest_TestExtension');
        $this->setMockHttpResponse(
            'paymentexpress/tests/Mock/PxPayCompletePurchaseSuccess.txt'
        );
        $this->getHttpRequest()->query->replace(array('result' => 'abc123'));
        $response = $this->get($staticUrl);
        // We should get a redirect to the complete url (shop/complete)
        $this->assertEquals($response->getStatusCode(), 302);
        $headers = $response->getHeaders();
        $this->assertEquals(
            Director::baseURL()."shop/complete",
            $headers['Location'],
            "redirected to shop/complete"
        );
        PaymentGatewayController::remove_extension('PaymentGatewayControllerTest_TestExtension');
    }

    public function testStaticRouteWithoutAction()
    {
        PaymentGatewayController::add_extension('PaymentGatewayControllerTest_TestExtension');
        // Configure gateway to use static route
        Config::inst()->update('GatewayInfo', 'PaymentExpress_PxPay', array('use_static_route' => true));
        $staticUrl = 'paymentendpoint/gateway/PaymentExpress_PxPay?id=62b26e0a8a77f60cce3e9a7994087b0e';

        $response = $this->get($staticUrl);
        // should return 404, because there's no action
        $this->assertEquals($response->getStatusCode(), 404);

        $response = $this->get($staticUrl . '&action=bogus');
        // should return 404, because the action is invalid
        $this->assertEquals($response->getStatusCode(), 404);

        $response = $this->get($staticUrl . '&action=cancel');
        // We should get a redirect to the cancel url (shop/incomplete)
        $this->assertEquals($response->getStatusCode(), 302);
        $headers = $response->getHeaders();
        $this->assertEquals(
            Director::baseURL()."shop/incomplete",
            $headers['Location'],
            "redirected to shop/incomplete"
        );
        PaymentGatewayController::remove_extension('PaymentGatewayControllerTest_TestExtension');
    }
}

class PaymentGatewayControllerTest_TestExtension extends Extension implements TestOnly
{
    public function updatePaymentFromRequest(\SS_HTTPRequest $request, $gateway)
    {
        if ($gateway === 'PaymentExpress_PxPay') {
            return Payment::get()->filter('Identifier', $request->getVar('id'))->first();
        }
        return null;
    }

    public function updatePaymentActionFromRequest(&$action, Payment $payment, SS_HTTPRequest $request)
    {
        if ($payment->Gateway == 'PaymentExpress_PxPay' && $request->getVar('action')) {
            $action = $request->getVar('action');
        }
    }
}
