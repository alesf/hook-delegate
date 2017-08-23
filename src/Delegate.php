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

            $ticket = Ticket::find($object->ticket_id);
            $contact = FindContact::byIP($ticket->ip);

            if (!is_null($contact->api_host) && !is_null($contact->token)) {
                // wait until the linked ticket has an ash_token_ip
                // the ash_token_ip is filled by an observer, so it's not always
                // on time.

                while(empty($ticket->ash_token_ip)) {
                    usleep(250);
                }

                // convert the event to an incident
                $incident = Incident::fromEvent($object);

                // we have a contact with a delegated AbuseIO instance
                // use that AbuseIO's api to create a incident

                $token = $contact->token;
                $url = $contact->api_host . "/incidents";

                // send incident
                self::send($url, $token, $incident->toArray());
            }
        }

        if ($object instanceof $models['Ticket'] && $event == 'updating') {
            $ticket = $object;
            // we are only interested in the status_id
            $original = $ticket->getOriginal();
            if ($ticket->status_id !== $original['status_id']) {

                if ($ticket->hasParent()) {
                    // sync to parent
                    $api = self::getTicketUrl($object, false, '/synccontactstatus');
                    if (!empty($api)) {
                        // send ticket
                        self::send($api['url'], $api['token'], $object->toArray());
                    }
                }

                if ($ticket->hasChild()) {
                    // sync to child
                    $api = self::getTicketUrl($object, true, '/syncstatus');
                    if (!empty($api)) {
                        // send ticket
                        self::send($api['url'], $api['token'], $object->toArray());
                    }
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
     *  the ticket to be used
     *
     * @param bool $parent
     *  are we the parent ticket ?
     *
     * @param string $part
     *  extra part of the url
     *
     * @return array
     */
    private static function getTicketUrl(Ticket $ticket, $parent = true, $part = '')
    {
        $result = [];

        if ($parent) {
            // return the contact api url
            $contact = FindContact::byIP($ticket->ip);
            if (!is_null($contact->api_host) && !is_null($contact->token)) {
                $result['url'] = $contact->api_host . '/tickets' . $part;
                $result['token'] = $contact->token;
            }
        } else {
            // return the remote_api_url from the ticket
            if (!is_null($ticket->remote_api_url) && !is_null($ticket->remote_api_token)) {
                $result['url'] = $ticket->remote_api_url . $part;
                $result['token'] = $ticket->remote_api_token;
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