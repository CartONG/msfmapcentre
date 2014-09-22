<?php

namespace Cartong\MSFBundle\Security;

use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;

class UserProvider implements UserProviderInterface
{
    public function loadUserByUsername($username)
    {
        // GeonetworkAuthenticator::authenticateToken should create user
        // We need to know user and password to forward request to Geonetwork webservice.
        throw new UsernameNotFoundException(
            sprintf('Username "%s" does not exist.', $username)
        );
    }

    public function refreshUser(UserInterface $user)
    {
        return $user;
    }

    public function supportsClass($class)
    {
        return $class === 'Cartong\MSFBundle\Security\User';
    }
}
