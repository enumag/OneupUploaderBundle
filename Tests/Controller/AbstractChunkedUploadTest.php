<?php

declare(strict_types=1);

namespace Oneup\UploaderBundle\Tests\Controller;

use Oneup\UploaderBundle\Event\PostChunkUploadEvent;
use Oneup\UploaderBundle\Event\PreUploadEvent;
use Oneup\UploaderBundle\Event\ValidationEvent;
use Oneup\UploaderBundle\UploadEvents;
use Symfony\Component\EventDispatcher\Event;

abstract class AbstractChunkedUploadTest extends AbstractUploadTest
{
    protected $total = 6;

    public function testChunkedUpload(): void
    {
        // assemble a request
        $me = $this;
        $endpoint = $this->helper->endpoint($this->getConfigKey());
        $basename = '';
        $validationCount = 0;

        for ($i = 0; $i < $this->total; ++$i) {
            $file = $this->getNextFile($i);

            if ('' === $basename) {
                $basename = $file->getClientOriginalName();
            }

            $client = static::createClient();
            $dispatcher = $client->getContainer()->get('event_dispatcher');

            $dispatcher->addListener(UploadEvents::PRE_UPLOAD, function (PreUploadEvent $event) use (&$me, $basename): void {
                $file = $event->getFile();

                $me->assertNotNull($file->getClientSize());
                $me->assertGreaterThan(0, $file->getClientSize());
                $me->assertEquals($file->getBasename(), $basename);
            });

            $dispatcher->addListener(UploadEvents::VALIDATION, function (ValidationEvent $event) use (&$validationCount): void {
                ++$validationCount;
            });

            $client->request('POST', $endpoint, $this->getNextRequestParameters($i), [$file], $this->requestHeaders);
            $response = $client->getResponse();

            $this->assertTrue($response->isSuccessful());
            $this->assertSame($response->headers->get('Content-Type'), 'application/json');
        }

        $this->assertSame(1, $validationCount);

        foreach ($this->getUploadedFiles() as $file) {
            $this->assertTrue($file->isFile());
            $this->assertTrue($file->isReadable());
            $this->assertSame(120, $file->getSize());
        }
    }

    public function testEvents(): void
    {
        $endpoint = $this->helper->endpoint($this->getConfigKey());

        // prepare listener data
        $me = $this;
        $chunkCount = 0;
        $uploadCount = 0;
        $chunkSize = $this->getNextFile(0)->getSize();

        for ($i = 0; $i < $this->total; ++$i) {
            // each time create a new client otherwise the events won't get dispatched
            $client = static::createClient();
            $dispatcher = $client->getContainer()->get('event_dispatcher');

            $dispatcher->addListener(UploadEvents::POST_CHUNK_UPLOAD, function (PostChunkUploadEvent $event) use (&$chunkCount, $chunkSize, &$me): void {
                ++$chunkCount;

                $chunk = $event->getChunk();

                $me->assertEquals($chunkSize, $chunk->getSize());
            });

            $dispatcher->addListener(UploadEvents::POST_UPLOAD, function (Event $event) use (&$uploadCount): void {
                ++$uploadCount;
            });

            $client->request('POST', $endpoint, $this->getNextRequestParameters($i), [$this->getNextFile($i)], $this->requestHeaders);
        }

        $this->assertSame($this->total, $chunkCount);
        $this->assertSame(1, $uploadCount);
    }

    abstract protected function getNextRequestParameters($i);

    abstract protected function getNextFile($i);
}
