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

namespace Atos\EventListeners;

use Atos\Atos;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Mailer\MailerFactory;

/**
 * Class SendEmailConfirmation.
 *
 * @author manuel raynaud <mraynaud@openstudio.fr>
 * @author franck allimant <franck@cqfdev.fr>
 */
class SendConfirmationEmail implements EventSubscriberInterface
{
    /**
     * @var MailerFactory
     */
    protected $mailer;

    public function __construct(MailerFactory $mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * @throws \Exception if the message cannot be loaded
     */
    public function sendConfirmationEmail(OrderEvent $event): void
    {
        if (Atos::getConfigValue('send_confirmation_message_only_if_paid')) {
            // We send the order confirmation email only if the order is paid
            $order = $event->getOrder();

            if (!$order->isPaid() && $order->getPaymentModuleId() == Atos::getModuleId()) {
                $event->stopPropagation();
            }
        }
    }

    /*
     * @params OrderEvent $order
     * Checks if order payment module is paypal and if order new status is paid, send an email to the customer.
     */

    public function updateStatus(OrderEvent $event, EventDispatcher $dispatcher): void
    {
        $order = $event->getOrder();

        if ($order->isPaid() && $order->getPaymentModuleId() == Atos::getModuleId()) {
            if (Atos::getConfigValue('send_payment_confirmation_message')) {
                $this->mailer->sendEmailToCustomer(
                    Atos::CONFIRMATION_MESSAGE_NAME,
                    $order->getCustomer(),
                    [
                        'order_id' => $order->getId(),
                        'order_ref' => $order->getRef(),
                    ]
                );
            }

            // Send confirmation email if required.
            if (Atos::getConfigValue('send_confirmation_message_only_if_paid')) {
                $dispatcher->dispatch($event, TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL);
            }

            Tlog::getInstance()->debug('Confirmation email sent to customer '.$order->getCustomer()->getEmail());
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['updateStatus', 128],
            TheliaEvents::ORDER_SEND_CONFIRMATION_EMAIL => ['sendConfirmationEmail', 129],
        ];
    }
}
