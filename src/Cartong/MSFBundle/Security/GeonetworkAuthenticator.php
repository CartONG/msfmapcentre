<?php

namespace Cartong\MSFBundle\Security;

//use Guzzle\Http\Client;
use Cartong\MSFBundle\Geonetwork\Client;

use Guzzle\Parser\Cookie\CookieParser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\SimpleFormAuthenticatorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class GeonetworkAuthenticator implements SimpleFormAuthenticatorInterface
{
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function authenticateToken(TokenInterface $token, UserProviderInterface $userProvider, $providerKey)
    {
        // Login
        $username = $token->getUsername();
        $password = $token->getCredentials();
        $payload = ['username'=>$username, 'password'=>$password];
        $response = $this->client->post('j_spring_security_check', $payload);

        if ($response->getStatusCode()===302)
        {
            $location = (string)$response->getHeader('Location');
            if (strpos($location, 'failure=true')===false)
            {
                $parser = new CookieParser();
                $cookie = $parser->parseCookie($response->getSetCookie(), null, null, true);
                $jsessionID = $cookie['cookies']['JSESSIONID'];

                // Query groups from Geonetwork and convert to roles.
                $response = $this->client->get('srv/eng/xml.info?type=groups', $jsessionID);
                if ($response->getStatusCode()===200)
                {
                    $crawler = new Crawler((string)$response->getBody());
                    $groupsQuery = $crawler->filterXPath('//group/name');
                    $roles = ['ROLE_USER'];
                    foreach ($groupsQuery as $group)
                    {
                        $groupName = $group->textContent;
                        if ($groupName==='msfadmin')
                        {
                            $roles []= 'ROLE_ADMIN';
                        }
                    }

                    $user = new User($token->getUsername(), $token->getCredentials(), $jsessionID, $roles);
                    return new UsernamePasswordToken(
                        $user,
                        $user->getPassword(),
                        $providerKey,
                        $user->getRoles()
                    );
                }
            }
        }

        throw new AuthenticationException('Invalid username or password');
    }

    public function supportsToken(TokenInterface $token, $providerKey)
    {
        return $token instanceof UsernamePasswordToken
        && $token->getProviderKey() === $providerKey;
    }

    public function createToken(Request $request, $username, $password, $providerKey)
    {
        return new UsernamePasswordToken($username, $password, $providerKey);
    }
}