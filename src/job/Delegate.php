<?php

namespace AbuseIO\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;
use Laminas\Http\Client;

/**
 * Class Delegate
 * @package AbuseIO\Jobs
 */
class Delegate extends Job implements ShouldQueue, SelfHandling
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var array
     */
    protected $data = [];

    /**
     * Delegate constructor.
     * @param $data
     * @throws Exception
     */
    public function __construct($data)
    {
        $fields = ['url', 'token', 'data'];
        foreach ($fields as $field) {
            if (!is_array($data) || !array_key_exists($field, $data))
            {
                throw new Exception('Missing field ' . $field);
            }
        }

       $this->data = $data;
    }

    /**
     * handle method of the job, gets called every time the job runs
     */
    public function handle()
    {

        Log::notice('Sending data to ' . $this->data['url']);
        $client = new Client($this->data['url']);
        $client->setHeaders([
            'Accept'      => 'application/json',
            'X-API-TOKEN' => $this->data['token']
        ]);
        $client->setMethod('POST');
        $client->setParameterPost($this->data['data']);
        $response = $client->send();

        if (!$response->isSuccess()) {
            throw new Exception(
                sprintf(
                    "Failure, statuscode: %d\n body: %s\n",
                    $response->getStatusCode(),
                    $response->getBody()
                )
            );
        }
    }

    /**
     * gets called when the job fails
     */
    public function failed()
    {
        Log::notice("Couldn't connect to other AbuseIO instance, will try again");

        // wait 30 seconds for next attempt
        $this->release(30);
    }
}
