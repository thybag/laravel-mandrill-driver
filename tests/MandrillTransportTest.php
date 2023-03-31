<?php

namespace LaravelMandrill\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Illuminate\Mail\Mailable;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Mail;
use MailchimpTransactional\ApiClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;

class MailerSendTransportTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            'LaravelMandrill\MandrillServiceProvider',
        ];
    }

    protected function defineEnvironment($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('mail.driver', 'mandrill');

    }

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function mockMandrillAPiResponces(MockHandler $handler)
    {
        // Inject a mocked instance of guzzel into the underlying mandrill transport.
        // This will allow us to test the mail fully.
        $mockApiClient = new class($handler) extends ApiClient {
            public function __construct($handler)
            {
                parent::__construct();

                // Swap in mocked Guzzle instance
                $this->requestClient = new Client([
                    'handler' => HandlerStack::create($handler)
                ]);
            }
        };

        Mail::getFacadeRoot()->mailer('mandrill')->getSymfonyTransport()->setClient($mockApiClient);
    }


    public function testRun()
    {
        // Mock API for successful email send
        $mock = new MockHandler([
            new Response(200, 
                ['content-type' => 'application/json'], 
                json_encode([
                    "email" => "testemail@example.com",
                    "status" => "queued",
                    "_id" => "111111111111111"
                ])
            )
        ]);

        // Mock Mandrill API
        $this->mockMandrillAPiResponces($mock);

        // Setup test email.
        $testMail = new class() extends Mailable {
            public function build()
            {
                return $this->from('mandrill@test.com', 'Test') ->html('Hello World');
            }
        };

        // Ensure event contains expected data.
        Event::listen(MessageSent::class, function($event)
        {
            // Check Mandrill _id was passed back
            $this->assertEquals($event->sent->getMessageId(), "111111111111111");
            // Check correct from email.
            $this->assertEquals($event->message->getFrom()[0]->getAddress(), "mandrill@test.com");
            // Check correct to email.
            $this->assertEquals($event->message->getTo()[0]->getAddress(), "testemail@example.com");
        });

        // Trigger event
        Mail::to('testemail@example.com')->send($testMail);
    }
}