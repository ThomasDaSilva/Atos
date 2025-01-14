<?php

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */

/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */

namespace Atos;

use Atos\Model\AtosCurrencyQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Routing\Router;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Template\ParserInterface;
use Thelia\Core\Translation\Translator;
use Thelia\Install\Database;
use Thelia\Log\Tlog;
use Thelia\Model\Config;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Tools\URL;

class Atos extends AbstractPaymentModule
{
    const MODULE_DOMAIN = 'atos';

    /**
     * The confirmation message identifier.
     */
    const CONFIRMATION_MESSAGE_NAME = 'atos_payment_confirmation';

    private $parameters;

    public function postActivation(ConnectionInterface $con = null): void
    {
        // Setup some default values
        if (null === self::getConfigValue('atos_merchantId', null)) {
            self::setConfigValue('atos_transactionId', 1);
            self::setConfigValue('minimum_amount', 0);
            self::setConfigValue('maximum_amount', 0);
            self::setConfigValue('send_payment_confirmation_message', 1);
        }

        // Try to chmod binaries if they're not executables
        $binFile = self::getBinDirectory().'request';
        if (!is_executable($binFile)) {
            @chmod($binFile, 0755);
        }

        $binFile = self::getBinDirectory().'response';
        if (!is_executable($binFile)) {
            @chmod($binFile, 0755);
        }

        $database = new Database($con);

        $database->insertSql(null, [
            __DIR__.DS.'Config'.DS.'thelia.sql',
        ]);

        // Create payment confirmation message from templates, if not already defined
        $email_templates_dir = __DIR__.DS.'I18n'.DS.'email-templates'.DS;

        if (null === MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)) {
            $message = new Message();

            $message
                ->setName(self::CONFIRMATION_MESSAGE_NAME)
                ->setHtmlTemplateFileName('atos-payment-confirmation.html')
                ->setTextTemplateFileName('atos-payment-confirmation.txt')
                ->setLocale('en_US')
                ->setTitle('Atos payment confirmation')
                ->setSubject('Payment of order {$order_ref}')
                ->setLocale('fr_FR')
                ->setTitle('Confirmation de paiement par Atos')
                ->setSubject('Confirmation du paiement de votre commande {$order_ref}')
                ->save()
            ;
        }

        $this->replacePath();
    }

    public function update($currentVersion, $newVersion, ConnectionInterface $con = null): void
    {
        // Migrate old configuration
        if (null === self::getConfigValue('atos_merchantId', null)) {
            if (null !== $atosConfigs = ConfigQuery::create()->filterByName('atos_%', Criteria::LIKE)->find()) {
                /** @var Config $atosConfig */
                foreach ($atosConfigs as $atosConfig) {
                    self::setConfigValue($atosConfig->getName(), $atosConfig->getValue());

                    $atosConfig->delete($con);
                }
            }
        }

        parent::update($currentVersion, $newVersion, $con);
    }

    public function destroy(ConnectionInterface $con = null, $deleteModuleData = false): void
    {
        if ($deleteModuleData) {
            $database = new Database($con);

            $database->execute('drop table `atos_currency`');

            MessageQuery::create()->findOneByName(self::CONFIRMATION_MESSAGE_NAME)->delete();
        }
    }

    protected function replacePath(): void
    {
        $pathfile = $this->getPathfilePath();

        $pathfileContent = @file_get_contents($pathfile.'.dist');

        if ($pathfileContent) {
            $pathfileContent = str_replace('__PATH__', __DIR__, $pathfileContent);

            if (!file_put_contents($this->getConfigDirectory().'pathfile', $pathfileContent)) {
                throw new \RuntimeException(
                    Translator::getInstance()->trans(
                        'File %file must be writable, please check Atos/Config directory permissions.',
                        ['%file' => 'pathfile'],
                        self::MODULE_DOMAIN
                    )
                );
            }
        } else {
            throw new \RuntimeException(
                Translator::getInstance()->trans(
                    'Failed to read the %file file. Please check file and directory permissions.',
                    ['%file' => $pathfile.'.dist'],
                    self::MODULE_DOMAIN
                )
            );
        }
    }

    /**
     * @param string $key   atos key parameter
     * @param string $value parameter value
     *
     * @return $this
     */
    protected function addParam($key, $value)
    {
        $this->parameters = sprintf('%s %s=%s', $this->parameters, $key, $value);

        return $this;
    }

    protected function getParameters()
    {
        return trim($this->parameters);
    }

    /**
     * generate a transaction id for atos solution.
     *
     * @return int|mixed
     */
    private function generateTransactionID()
    {
        $transId = self::getConfigValue('atos_transactionId', 1);

        $transId = 1 + (int) $transId;

        if (\strlen($transId) > 6) {
            $transId = 1;
        }

        self::setConfigValue('atos_transactionId', $transId);

        return sprintf('%06d', $transId);
    }

    /**
     *  Method used by payment gateway.
     *
     *  If this method return a \Thelia\Core\HttpFoundation\Response instance, this response is send to the
     *  browser.
     *
     *  In many cases, it's necessary to send a form to the payment gateway.
     *  On your response you can return this form already completed, ready to be sent
     *
     * @param \Thelia\Model\Order $order processed order
     *
     * @return \Thelia\Core\HttpFoundation\Response|null
     */
    public function pay(Order $order)
    {
        $pathBin = self::getBinDirectory().'request';

        $atosCurrency = AtosCurrencyQuery::create()->findPk(
            $order->getCurrency()->getCode()
        );

        if (null == $atosCurrency) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Atos does not supprot this currency : %s',
                    $order->getCurrency()->getCode()
                )
            );
        }

        $amount = $order->getTotalAmount();
        $amount = number_format($amount, $atosCurrency->getDecimals(), '', '');

        $transactionId = $this->generateTransactionID();

        $order->setTransactionRef($transactionId)->save();

        /** @var Router $router */
        $router = $this->getContainer()->get('router.atos');

        $this
            ->addParam('pathfile', self::getPathfilePath())
            ->addParam('merchant_id', self::getConfigValue('atos_merchantId'))
            ->addParam('customer_email', $order->getCustomer()->getEmail())
            ->addParam('currency_code', $atosCurrency->getAtosCode())
            ->addParam('amount', $amount)
            ->addParam('language', $order->getLang()->getCode())
            ->addParam('transaction_id', $transactionId)
            ->addParam('order_id', $order->getId())
            ->addParam('automatic_response_url', URL::getInstance()->absoluteUrl($router->generate('atos.payment.confirmation')))
            ->addParam('cancel_return_url', URL::getInstance()->absoluteUrl($router->generate('atos.payment.cancel', ['orderId' => $order->getId()])))
            ->addParam('normal_return_url', $this->getPaymentSuccessPageUrl($order->getId()))
        ;

        $encrypt = exec(sprintf('%s %s', $pathBin, $this->getParameters()));

        if (!empty($encrypt)) {
            $datas = explode('!', $encrypt);

            if ($datas[1] == '' && $datas[2] == '') {
                throw new \RuntimeException(
                    Translator::getInstance()->trans('Request binary not found in "%path"', ['%path' => $pathBin])
                );
            } elseif ($datas[1] != 0) {
                throw new \RuntimeException($datas[2]);
            } else {
                /** @var ParserInterface $parser */
                $parser = $this->getContainer()->get('thelia.parser');

                $parser->setTemplateDefinition(
                    $parser->getTemplateHelper()->getActiveFrontTemplate(),
                    true
                );

                $content = $parser->render('atos/payment.html', [
                    'site_name' => self::getConfigValue('store_name'),
                    'form' => $datas[3],
                    'order_id' => $order->getId(),
                ]);

                return Response::create($content);
            }
        } else {
            throw new \RuntimeException(
                Translator::getInstance()->trans(
                    'Empty response recevied from Atos binary "%path". Please check path and permissions.',
                    ['%path' => $pathBin],
                    self::MODULE_DOMAIN
                )
            );
            // FIXME : show something to the customer
        }
    }

    /**
     * @return bool true to allow usage of this payment module, false otherwise
     */
    public function isValidPayment()
    {
        $valid = false;

        // Check config files
        $parmcomFile = self::getConfigDirectory().'parmcom.'.self::getConfigValue('atos_merchantId', '0');
        $certifFile = self::getConfigDirectory().'certif.fr.'.self::getConfigValue('atos_merchantId', '0');

        if (is_readable($parmcomFile) && is_readable($certifFile)) {
            $mode = self::getConfigValue('atos_mode', false);

            // If we're in test mode, do not display Payzen on the front office, except for allowed IP addresses.
            if ('TEST' == $mode) {
                $raw_ips = explode("\n", self::getConfigValue('atos_allowed_ip_list', ''));

                $allowed_client_ips = [];

                foreach ($raw_ips as $ip) {
                    $allowed_client_ips[] = trim($ip);
                }

                $client_ip = $this->getRequest()->getClientIp();

                $valid = \in_array($client_ip, $allowed_client_ips);
            } elseif ('PRODUCTION' == $mode) {
                $valid = true;
            }

            if ($valid) {
                // Check if total order amount is in the module's limits
                $valid = $this->checkMinMaxAmount();
            }
        } else {
            Tlog::getInstance()->addWarning(
                Translator::getInstance()->trans(
                    'Atos payment module is nort properly configured. Please check module configuration in your back-office.',
                    [],
                    self::MODULE_DOMAIN
                )
            );
        }

        return $valid;
    }

    /**
     * Check if total order amount is in the module's limits.
     *
     * @return bool true if the current order total is within the min and max limits
     */
    protected function checkMinMaxAmount()
    {
        // Check if total order amount is in the module's limits
        $order_total = $this->getCurrentOrderTotalAmount();

        $min_amount = self::getConfigValue('atos_minimum_amount', 0);
        $max_amount = self::getConfigValue('atos_maximum_amount', 0);

        return
            $order_total > 0
            &&
            ($min_amount <= 0 || $order_total >= $min_amount) && ($max_amount <= 0 || $order_total <= $max_amount);
    }

    public static function getBinDirectory()
    {
        return __DIR__.DS.'bin'.DS;
    }

    public static function getConfigDirectory()
    {
        return __DIR__.DS.'Config'.DS;
    }

    public static function getPathfilePath()
    {
        return self::getConfigDirectory().'pathfile';
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR.ucfirst(self::getModuleCode()).'/I18n/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
