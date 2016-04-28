<?php

/*
 * This file is part of NotificationPusher.
 *
 * (c) 2013 Cédric Dugat <cedric@dugat.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sly\NotificationPusher\Adapter;

use Sly\NotificationPusher\Model\BaseOptionedModel;
use Sly\NotificationPusher\Model\PushInterface;
use Sly\NotificationPusher\Model\DeviceInterface;
use Sly\NotificationPusher\Exception\AdapterException;
use Sly\NotificationPusher\Exception\PushException;
use Sly\NotificationPusher\Collection\DeviceCollection;

/**
 * APNS raw adapter.
 *
 * @uses \Sly\NotificationPusher\Adapter\BaseAdapter
 *
 * @author Christian Fuentes
 */
class Apnsraw extends BaseAdapter
{
    private $openedClient = null;

    private $feedbackClient;

    private $errorCode = 0;

    /**
     * {@inheritdoc}
     *
     * @throws \Sly\NotificationPusher\Exception\AdapterException
     */
    public function __construct(array $parameters = array())
    {
        parent::__construct($parameters);

        $cert = $this->getParameter('certificate');

        if (false === file_exists($cert)) {
            throw new AdapterException(sprintf('Certificate %s does not exist', $cert));
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Sly\NotificationPusher\Exception\PushException
     */
    public function push(PushInterface $push)
    {
        $client = $this->getOpenedServiceClient();

        $pushedDevices = new DeviceCollection();

        foreach ($push->getDevices() as $device) {
            $message = $this->getMessage($device, $push->getMessage());

            $result = fwrite($client, $msg, strlen($msg));

            if (!$result) {
                throw new PushException('Message could not be delivered');
            }

            $this->readResponse();
        }

        $this->closeClient();

        return $pushedDevices;
    }

    protected function getApnsUri()
    {
        if ($this->isProductionEnvironment()) {
            return 'ssl://gateway.push.apple.com:2195';
        } else {
            return 'ssl://gateway.sandbox.push.apple.com:2195';
        }
    }

    /**
     * Feedback.
     *
     * @return array
     */
    public function getFeedback()
    {
        // to develop
    }

    /**
     * Get opened ServiceClient.
     *
     * @return ServiceAbstractClient
     */
    private function getOpenedServiceClient()
    {
        if (is_null($this->openedClient)) {
            $this->streamContext = stream_context_create();
            stream_context_set_option($this->streamContext, 'ssl', 'local_cert', $this->getParameter('certificate'));
            if (!is_null($this->getParameter('passPhrase'))) {
                stream_context_set_option($ctx, 'ssl', 'passphrase', $this->getParameter('passPhrase'));
            }

            // Open a connection to the APNS server
            $this->openedClient = stream_socket_client(
                $this->getApnsUri(),
                $err,
                $errstr,
                60,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
                $this->streamContext
            );

            if (!$this->openedClient) {
                throw new PushException('Can not connect to Apple APNS gateway');
            }

            stream_set_blocking($this->openedClient, 0);
        }

        return $this->openedClient;
    }

    private function closeClient()
    {
        fclose($this->openedClient);
        $this->openedClient = null;
    }

    public function getResponse()
    {
        return $this->errorCode;
    }

    protected function readResponse()
    {
        $this->errorCode = 0;
        $appleResponse = fread($this->openedClient, 6);

        if ($appleResponse) {
            //unpack the error response (first byte 'command" should always be 8)
            $errorResponse = unpack('Ccommand/Cstatus_code/Nidentifier', $appleResponse);

            if ($error_response['status_code'] > 0) {
                $this->errorCode = $error_response['status_code'];
            }
        }
    }

    /**
     * Get service message from origin.
     *
     * @param \Sly\NotificationPusher\Model\DeviceInterface                    $device  Device
     * @param BaseOptionedModel|\Sly\NotificationPusher\Model\MessageInterface $message Message
     *
     * @return string
     */
    public function getMessage(DeviceInterface $device, BaseOptionedModel $message)
    {
        $badge = ($message->hasOption('badge'))
            ? (int) ($message->getOption('badge') + $device->getParameter('badge', 0))
            : 0
        ;

        // Create the payload body
        $body['aps'] = array(
            'alert' => $message->getText(),
            'sound' => 'default',
            'badge' => $badge,
        );
        $body['aps'] = array_merge($body['aps'], $message->getOption('custom', array()));

        // Encode the payload as JSON
        $payload = json_encode($body);

        // Build the binary notification
        //$msg = chr(0).pack('n', 32).pack('H*', $device->getToken()).pack('n', strlen($payload)).$payload;

        $inner =
            chr(1)
            .pack('n', 32)
            .pack('H*', $device->getToken())

            .chr(2)
            .pack('n', strlen($payload))
            .$payload

            .chr(3)
            .pack('n', 4)
            .pack('N', rand(1, 9999))

            .chr(4)
            .pack('n', 4)
            .pack('N', time() + 86400)

            .chr(5)
            .pack('n', 1)
            .chr(10);

        $notification =
            chr(2)
            .pack('N', strlen($inner))
            .$inner;

        return $notification;
    }

    /**
     * {@inheritdoc}
     */
    public function supports($token)
    {
        return (ctype_xdigit($token) && 64 == strlen($token));
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinedParameters()
    {
        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultParameters()
    {
        return array('passPhrase' => null);
    }

    /**
     * {@inheritdoc}
     */
    public function getRequiredParameters()
    {
        return array('certificate');
    }
}
