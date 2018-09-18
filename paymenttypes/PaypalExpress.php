<?php namespace Responsiv\Pay\PaymentTypes;

use Cms\Classes\Page;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Redirect;
use October\Rain\Exception\ApplicationException;
use Responsiv\Pay\Classes\GatewayBase;
use Responsiv\Pay\Models\Invoice;
use Exception;
use Omnipay\Omnipay;

class PaypalExpress extends GatewayBase
{
    /**
     * {@inheritDoc}
     */
    public function gatewayDetails()
    {
        return [
            'name' => 'PayPal Express',
            'description' => 'PayPal Express payment method with payment form hosted on your server'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function defineFormFields()
    {
        return 'fields.yaml';
    }

    /**
     * {@inheritDoc}
     */
    public function defineValidationRules()
    {
        return [
            'api_signature' => 'required',
            'api_username' => 'required',
            'api_password' => 'required',
            'success_page' => 'required',
            'cancel_page' => 'required'

        ];
    }

    /**
     * {@inheritDoc}
     */
    public function initConfigData($host)
    {
        $host->test_mode = true;
        $host->invoice_status = $this->createInvoiceStatusModel()->getPaidStatus();
    }

    /**
     * Action field options
     */
    public function getCardActionOptions()
    {
        return [
            'purchase' => 'Purchase',
            'authorize' => 'Authorization only'
        ];
    }

    /**
     * Cancel page field options
     */
    public function getCancelPageOptions()
    {
        return Page::getNameList();
    }

    /**
     * Success page field options
     */
    public function getSuccessPageOptions()
    {
        return Page::getNameList();
    }

    /**
     * {@inheritDoc}
     */
    public function registerAccessPoints()
    {
        return array(
            'paypal_express_return_url' => 'processReturnUrl',
            'paypal_express_cancel_url' => 'processCancelUrl'
        );
    }

    public function getReturnUrl()
    {
        return $this->makeAccessPointLink('paypal_express_return_url');
    }

    public function getCancelUrl()
    {
        return $this->makeAccessPointLink('paypal_express_cancel_url');
    }

    /**
     * Status field options.
     */
    public function getInvoiceStatusOptions()
    {
        return $this->createInvoiceStatusModel()->listStatuses();
    }


    /**
     * {@inheritDoc}
     */
    public function processPaymentForm($data, $invoice)
    {
        $host = $this->getHostObject();

        /*
         * Send payment request
         */
        $gateway = Omnipay::create('PayPal_Express');
        $gateway->setSignature($host->api_signature);
        $gateway->setUsername($host->api_username);
        $gateway->setPassword($host->api_password);
        $gateway->setTestMode($host->test_mode);

        $customerDetails = (object)$invoice->getCustomerDetails();

        $invoiceHash = $invoice->getUniqueHash();

        $cardData = [
            'firstName' => $customerDetails->first_name,
            'lastName' => $customerDetails->last_name,
        ];

        $cardAction = $host->card_action == 'purchase' ? 'purchase' : 'authorize';
        $totals = (object)$invoice->getTotalDetails();

        $request = [
            'amount' => $totals->total,
            'currency' => $totals->currency,
            'card' => $cardData,
            'return_url' => $this->getReturnUrl() . '/' . $invoiceHash,
            'cancel_url' => $this->getCancelUrl() . '/' . $invoiceHash
        ];
        $response = $gateway->$cardAction($request)->send();

        if ($response->isSuccessful()) {
            $invoice->logPaymentAttempt('Successful payment', 1, null, null, null);
            $invoice->markAsPaymentProcessed();

            $invoice->setExternalReference($response->getTransactionReference());

            $invoice->updateInvoiceStatus($host->invoice_status);
        } elseif ($response->isRedirect()) {

            $invoice->logPaymentAttempt('Started payment', 0, $request,
                ['transaction_reference' => $response->getTransactionReference()], $response->getMessage()
            );
            $invoice->setExternalReference($response->getTransactionReference());
            $invoice->save();

            return Redirect::to($response->getRedirectUrl());
        } else {
            $errorMessage = $response->getMessage();
            $invoice->logPaymentAttempt($errorMessage, 0, null, null, null);
            throw new ApplicationException($errorMessage);
        }
    }


    public function processReturnUrl($params)
    {

        try {
            /** @var Invoice $invoice */
            $invoice = null;

            $hash = array_key_exists(0, $params) ? $params[0] : null;
            if (!$hash) {
                throw new ApplicationException('Invoice not found');
            }

            $invoice = $this->createInvoiceModel()->findByUniqueHash($hash);
            if (!$invoice) {
                throw new ApplicationException('Invoice not found');
            }

            if (!$paymentMethod = $invoice->getPaymentMethod()) {
                throw new ApplicationException('Payment method not found');
            }

            if ($paymentMethod->getGatewayClass() != self::class) {
                throw new ApplicationException('Invalid payment method');
            }

            $token = Input::get('token');
            if(!$token || $invoice->getExternalReference() != $token){
                throw new ApplicationException("Invalid token");
            }

            // Valid transaction
            if ($invoice->markAsPaymentProcessed()) {
                $invoice->logPaymentAttempt('Successful payment', 1, [], Input::get(), Input::get('PayerID'));
                $invoice->updateInvoiceStatus($paymentMethod->invoice_status);

                return Redirect::to(Page::url($paymentMethod->success_page, ['hash' => $invoice->hash]));
            }
            else {
                // Invalid transaction. Abort
                $invoice->logPaymentAttempt('Invalid payment notification', 0, [], Input::get(), null);
                return Redirect::to(Page::url($paymentMethod->cancel_page, ['hash' => $invoice->hash]));
            }

        } catch (Exception $ex) {
            if ($invoice) {
                $invoice->logPaymentAttempt($ex->getMessage(), 0, [], $_POST, null);
            }

            throw new ApplicationException($ex->getMessage());
        }

    }
}
