<?php

namespace AbuseIO\Hook;

use AbuseIO\Jobs\FindContact;
use AbuseIO\Hook\HookInterface;
use AbuseIO\Models\Incident;
use AbuseIO\Models\Ticket;
use Zend\Http\Client;
use Log as Logger;

class Delegate implements HookInterface
{
    /**
     * dictated by HookInterface
     * the method called from hook-common
     *
     * @param $object
     * @param $event
     */
    public static function call($object, $event)
    {
        // valid models we listen to
        $models =
            [
                'Event' => \AbuseIO\Models\Event::class,
                'Ticket' => \AbuseIO\Models\Ticket::class,
            ];

        if ($object instanceof $models['Event'] && $event == 'created') {
            // convert the event to an incident
            $incident = Incident::fromEvent($object);
            $contact = FindContact::byIP($incident->ip);

            if (!is_null($contact->api_host) && !is_null($contact->token)) {
                // we have a contact with a delegated AbuseIO instance
                // use that AbuseIO's api to create a incident

                $token = $contact->token;
                $url = $contact->api_host . "/incidents";

                // send incident
                self::send($url, $token, $incident->toArray());
            }
        }

        if ($object instanceof $models['Ticket'] && $event == 'updating') {
            // we are only interested in the statuses
            $original = $object->getOriginal();
            if (($object->status_id !== $original['status_id']) ||
                ($object->contact_status_id !== $original['contact_status_id']))
            {
                // find the token and url
                $api = self::getTicketUrl($object, '/syncstatus');
                if (!empty($api)) {

                    // send ticket
                    self::send($api['url'], $api['token'], $object->toArray());
                }
            }
        }
    }

    /**
     * is this hook enabled
     *
     * @return bool
     */
    public static function isEnabled()
    {
        return true;
    }

    /**
     * get the api_url and token from the ticket
     *
     * @param Ticket $ticket
     * @param string $part
     * @return array
     */
    private static function getTicketUrl(Ticket $ticket, $part = '')
    {
        $result = [];

        if (!is_null($ticket->remote_api_url) && !is_null($ticket->remote_api_token)) {
            $result['url'] = $ticket->remote_api_url . $part;
            $result['token'] = $ticket->remote_api_token;
        } else {
            $contact = FindContact::byIP($ticket->ip);
            if (!is_null($contact->api_host) && !is_null($contact->token)) {
                $result['url'] = $contact->api_host . '/ticket' . $part;
                $result['token'] = $contact->token;
            }
        }

        return $result;
    }

    /**
     * places the api call
     *
     * @param $url
     * @param string $token
     * @param array $data
     */
    private static function send($url, $token = '', $data = [])
    {
        // send incident
        Logger::notice('Sending data to ' . $url);
        $client = new Client($url);
        $client->setHeaders([
            'Accept'      => 'application/json',
            'X-API-TOKEN' => $token
        ]);
        $client->setMethod('POST');
        $client->setParameterPost($data);
        $response = $client->send();

        if (!$response->isSuccess()) {
            Logger::notice(
                sprintf(
                    "Failure, statuscode: %d\n body: %s\n",
                    $response->getStatusCode(),
                    $response->getBody()
                )
            );
        }
    }
}