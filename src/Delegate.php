<?php

namespace AbuseIO\Hook;

use AbuseIO\Jobs\FindContact;
use AbuseIO\Hook\HookInterface;
use AbuseIO\Models\Incident;
use Log;
use Zend\Http\Client;

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

            if (!is_null($contact->api_host) && !is_null($contact->api_key)) {
                // we have a contact with a delegated AbuseIO instance
                // use that AbuseIO's api to create a incicent
                Log::debug('Sending incident to ' . $contact->api_host);

                // send incident
                $url = $contact->api_host . "/incidents";
                $token = $contact->api_key;
                $client = new Client($url);
                $client->setHeaders('X-API-TOKEN', $token);
                $client->setParameterPost($incident->toArray());
                $client->setMethod('POST');
                $response = $client->send();

                if (!$response->isSuccess()) {
                   Log::debug(
                       sprintf(
                           "Failure, statuscode: %d\n body: %s\n",
                           $response->getStatusCode(),
                           $response->getBody()
                       )
                   );
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
}