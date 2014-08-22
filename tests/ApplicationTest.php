<?php

/*
 * This file is part of the Tweets2Feed application.
 *
 * Copyright (c) Alan Gabriel Bem <alan.bem@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Silex\WebTestCase;

/**
 * ApplicationTest class.
 *
 * @author Alan Gabriel Bem <alan.bem@goldenline.pl>
 */
class ApplicationTest extends WebTestCase
{
    const SECRET = 's3cr3t';

    public function createApplication()
    {
        $app = require __DIR__ . '/../app/app.php';
        $app['exception_handler']->disable();
        $app['session.test'] = true;

        // config.php equivalent
        $app['debug'] = true;
        $app['secret'] = self::SECRET;
        $app['twitter.api.key'] = '';
        $app['twitter.api.secret'] = '';
        $app['twitter.oauth.token'] = '';
        $app['twitter.oauth.token_secret'] = '';
        $app['cache.default_ttl'] = 0;

        return $app;
    }

    /**
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    public function testHomepage()
    {
        $client = $this->createClient();
        $crawler = $client->request('GET', '/');

        $this->assertCount(1, $crawler->filter('form'));
        $this->assertCount(1, $crawler->filter('form button#generate'));

        return $crawler;
    }

    /**
     * @dataProvider invalidUsernames
     *
     * @param string $username
     */
    public function testSubmittingInvalidUsername($username)
    {
        /* @var $app \Silex\Application */
        $app = $this->app;
        $tests = $this;

        // in case of invalid username, http request - to check if Twitter user exist - should never be send
        $app['buzz'] = $app->share(function ($app) use ($tests) {

            $buzz = $tests
                ->getMockBuilder('Buzz\Browser')
                ->disableOriginalConstructor()
                ->setMethods(array('get'))
                ->getMock()
            ;

            $buzz
                ->expects($tests->never())
                ->method('get')
            ;

            return $buzz;
        });

        $client = $this->createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->selectButton('generate')->form(
            array(
                'form[username]' => $username,
            )
        );

        $crawler = $client->submit($form);

        $this->assertCount(1, $crawler->filter('form'));
        $this->assertCount(1, $crawler->filter('form .warning'));
        $this->assertCount(1, $crawler->filter('form button#generate'));
    }

    /**
     * @dataProvider validUsernames
     *
     * @param string $username
     * @param string $redirect
     */
    public function testSubmitForValidUsername($username, $redirect)
    {
        /* @var $app \Silex\Application */
        $app = $this->app;
        $tests = $this;

        // in case of invalid username, http request - to check if Twitter user exist - should never be send
        $app['buzz'] = $app->share(function ($app) use ($tests) {

            $buzz = $tests
                ->getMockBuilder('Buzz\Browser')
                ->disableOriginalConstructor()
                ->setMethods(array('get'))
                ->getMock()
            ;

            // Twitter 200 response
            $response = new Buzz\Message\Response();
            $response->setHeaders(array(
                'HTTP/1.1 200 OK',
                'cache-control: no-cache, no-store, must-revalidate, pre-check=0, post-check=0',
                'content-length: 184846',
                'content-type: text/html;charset=utf-8',
                'date: Sun, 30 Jun 2013 16:09:10 GMT',
                'expires: Tue, 31 Mar 1981 05:00:00 GMT',
                'last-modified: Sun, 30 Jun 2013 16:09:10 GMT',
                'ms: S',
                'pragma: no-cache',
                'server: tfe',
                'set-cookie: _twitter_sess=BAh7CiIKZmxhc2hJQzonQWN0aW9uQ29udHJvbGxlcjo6Rmxhc2g6OkZsYXNo%250ASGFzaHsABjoKQHVzZWR7ADoOcmV0dXJuX3RvIiFodHRwczovL3R3aXR0ZXIu%250AY29tL2FsYW5nYmVtOgxjc3JmX2lkIiVkOGZmYzZmMzdjZTAzMjM0ODRiODVj%250AODVkM2ExMWUxZToPY3JlYXRlZF9hdGwrCNVX2JU%252FAToHaWQiJWVmZTk2MDll%250AMjNiMzUxN2VkNTExOTBlNDg5ZTAxMTMx--35a4c55f68310dd13721bbe1c56b9eda1f236ac9; Path=/; Domain=.twitter.com; HTTPOnly',
                'set-cookie: guest_id=v1%3A137260855086782653; Domain=.twitter.com; Path=/; Expires=Tue, 30-Jun-2015 16:09:10 UTC',
                'status: 200 OK',
                'strict-transport-security: max-age=631138519',
                'x-frame-options: SAMEORIGIN',
                'x-transaction: 72fa4e5d1b0d39b9',
                'x-ua-compatible: IE=10,chrome=1',
                'x-xss-protection: 1; mode=block',
            ));

            $buzz
                ->expects($tests->once())
                ->method('get')
                ->will($tests->returnValue($response))
            ;

            return $buzz;
        });

        $client = $this->createClient();
        $crawler = $client->request('GET', '/');

        $form = $crawler->selectButton('generate')->form(
            array(
                'form[username]' => $username,
            )
        );

        $client->submit($form);

        $response = $client->getResponse();

        $this->assertTrue($response->isRedirect($redirect));
    }

    /**
     * @dataProvider feeds
     *
     * @param string $username
     * @param string $path
     * @param array $tweets
     * @param string $atom
     */
    public function testFeed($username, $path, $tweets, $atom)
    {
        /* @var $app \Silex\Application */
        $app = $this->app;
        $tests = $this;
        // mock Twitter oauth client
        $app['twitter.oauth.client'] = $app->share(function ($app) use ($tests, $tweets, $username) {

            $oauth = $tests
                ->getMockBuilder('OAuth')
                ->disableOriginalConstructor()
                ->setMethods(array('fetch', 'getLastResponse'))
                ->getMock()
            ;

            $oauth
                ->expects($tests->once())
                ->method('fetch')
                ->with('https://api.twitter.com/1.1/statuses/user_timeline.json', array('screen_name' => $username, 'count' => 200))
                ->will($tests->returnValue(true))
            ;

            $oauth
                ->expects($tests->once())
                ->method('getLastResponse')
                ->will($tests->returnValue($tweets))
            ;

            return $oauth;
        });

        $client = $this->createClient();
        $client->request('GET', $path);

        $response = $client->getResponse();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/atom+xml', $response->headers->get('Content-type'));
        $this->assertXmlStringEqualsXmlString($atom, $response->getContent());
    }

    public function testEmptyFeed()
    {
        /* @var $app \Silex\Application */
        $app = $this->app;
        $tests = $this;

        // mock Twitter oauth client
        $app['twitter.oauth.client'] = $app->share(function ($app) use ($tests) {

            $oauth = $tests
                ->getMockBuilder('OAuth')
                ->disableOriginalConstructor()
                ->setMethods(array('fetch', 'getLastResponse'))
                ->getMock()
            ;

            $oauth
                ->expects($tests->once())
                ->method('fetch')
                ->will($tests->returnValue(true))
            ;

            $oauth
                ->expects($tests->once())
                ->method('getLastResponse')
                ->will($tests->returnValue('{}')) // no tweets
            ;

            return $oauth;
        });

        $client = $this->createClient();
        $client->request('GET', '/a.atom?signature=f822101de3b2d44500e90a552772284e868ce97b');

        $response = $client->getResponse();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/atom+xml', $response->headers->get('Content-type'));

        // beacuse feed > updated element is dynamic, xml structure test is required here
        $this->assertSelectEquals('feed > title', '@a on Twitter', 1, $response->getContent());
        $this->assertSelectCount('feed > link', 2, $response->getContent());
        $this->assertSelectEquals('feed > id', 'https://twitter.com/a', 1, $response->getContent());
        $this->assertSelectCount('feed > author', 1, $response->getContent());
        $this->assertSelectEquals('feed > author > name', '@a', 1, $response->getContent());
    }

    public function invalidUsernames()
    {
        // TODO: add more invalid usernames
        return array(
            array(''), // empty
            array('useeeeeeeeeeernaaaaaaaaaaame'), // to long
            // invalid character(s)
            array('user!name'),
            array('user@name'),
            array('user#name'),
            array('user$name'),
            array('user%name'),
            array('user^name'),
            array('user&name'),
            array('user*name'),
            array('user(name'),
            array('user)name'),
            array('user-name'),
            array('user+name'),
            array('user{name'),
            array('user}name'),
            array('user[name'),
            array('user]name'),
            array('user\'name'), // user'name
            array('user\\name'), // user\name
            array('user"name'),
            array('user|name'),
            array('user,name'),
            array('user.name'),
            array('user/name'),
            array('user<name'),
            array('user>name'),
            array('user?name'),
        );
    }

    public function validUsernames()
    {
        // TODO: add more valid usernames and feed urls to test signatures
        return array(
            array('a', '/a.atom?signature=f822101de3b2d44500e90a552772284e868ce97b'),
            array('alangbem', '/alangbem.atom?signature=87ea11b701cfcd5e6ec73c8fb2fd2cb6032abf73'),
        );
    }

    public function feeds()
    {
        // TODO: add more varied feeds
        return array(
            array('alangbem', '/alangbem.atom?signature=87ea11b701cfcd5e6ec73c8fb2fd2cb6032abf73', $json = file_get_contents(__DIR__ . '/data/alangbem.json'), $atom = file_get_contents(__DIR__ . '/data/alangbem.atom')),
        );
    }
}
