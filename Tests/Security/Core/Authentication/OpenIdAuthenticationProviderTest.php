<?php
namespace Fp\OpenIdBundle\Tests\Security\Core\Authentication;

use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;

use Fp\OpenIdBundle\Security\Core\Authentication\Provider\OpenIdAuthenticationProvider;
use Fp\OpenIdBundle\Security\Core\User\UserManagerInterface;
use Fp\OpenIdBundle\Security\Core\Authentication\Token\OpenIdToken;
use Fp\OpenIdBundle\Security\Core\Exception\UsernameByIdentityNotFoundException;

class OpenIdAuthenticationProviderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function couldBeConstructedWithoutAnyArguments()
    {
        new OpenIdAuthenticationProvider($providerKey = 'main');
    }

    /**
     * @test
     */
    public function couldBeConstructedWithUserProviderAndUserChecker()
    {
        new OpenIdAuthenticationProvider(
            $providerKey = 'main',
            $this->createUserProviderMock(),
            $this->createUserCheckerMock()
        );
    }

    /**
     * @test
     */
    public function couldBeConstructedWithUserManagerUserCheckerAndCreateIfNotExistSetTrue()
    {
        new OpenIdAuthenticationProvider(
            $providerKey = 'main',
            $this->createUserManagerMock(),
            $this->createUserCheckerMock(),
            $createIfNotExist = true
        );
    }

    /**
     * @test
     *
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage $userChecker cannot be null, if $userProvider is not null.
     */
    public function throwIfTryConstructWithUserProviderButWithoutUserChecker()
    {
        new OpenIdAuthenticationProvider(
            $providerKey = 'main',
            $this->createUserManagerMock(),
            $userChecker = null
        );
    }

    /**
     * @test
     *
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage The $userProvider must implement UserManagerInterface if $createIfNotExists is true.
     */
    public function throwIfTryConstructWithoutUserManagerButWithCreateUserIfNotExistSetTrue()
    {
        new OpenIdAuthenticationProvider(
            $providerKey = 'main',
            $userProvider = null,
            $userChecker = null,
            $createIfNotExist = true
        );
    }

    /**
     * @test
     */
    public function shouldSupportOpenIdToken()
    {
        $providerKey = 'main';
        $authProvider = new OpenIdAuthenticationProvider($providerKey);

        $this->assertTrue($authProvider->supports(new OpenIdToken($providerKey, 'identity')));
    }

    /**
     * @test
     */
    public function shouldNotSupportNoneOpenIdToken()
    {
        $authProvider = new OpenIdAuthenticationProvider('main');

        $noneOpenIdToken = $this->getMock('Symfony\Component\Security\Core\Authentication\Token\TokenInterface');

        $this->assertFalse($authProvider->supports($noneOpenIdToken));
        $this->assertNull($authProvider->authenticate($noneOpenIdToken));
    }

    /**
     * @test
     */
    public function shouldNotSupportOpenIdTokenIfProviderKeyDiffers()
    {
        $providerKeyForProvider = 'main';
        $providerKeyForToken    = 'connect';

        $authProvider = new OpenIdAuthenticationProvider($providerKeyForProvider);
        $token = new OpenIdToken($providerKeyForToken, 'identity');

        $this->assertFalse($authProvider->supports($token));
        $this->assertNull($authProvider->authenticate($token));
    }

    public function testThatProviderKeyIsNotEmptyAfterDeserialization()
    {
        $providerKey = 'main';
        $token = unserialize(serialize(new OpenIdToken($providerKey, 'identity')));

        $this->assertEquals($providerKey, $token->getProviderKey());
    }

    /**
     * @test
     */
    public function shouldCreateAuthenticatedTokenUsingUserAndHisRolesFromToken()
    {
        $providerKey = 'main';
        $expectedIdentity = 'the_identity';
        $expectedAttributes = array('foo' => 'foo', 'bar' => 'bar_val');

        $expectedUserMock = $this->createUserMock();
        $expectedUserMock
            ->expects($this->any())
            ->method('getRoles')
            ->will($this->returnValue(array('foo', 'bar')))
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userProvider = null,
            $this->createUserCheckerMock()
        );

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser($expectedUserMock);
        $token->setAttributes($expectedAttributes);

        $authenticatedToken = $authProvider->authenticate($token);

        $this->assertInstanceOf('Fp\OpenIdBundle\Security\Core\Authentication\Token\OpenIdToken', $authenticatedToken);
        $this->assertNotSame($token, $authenticatedToken);
        $this->assertTrue($authenticatedToken->isAuthenticated());
        $this->assertEquals($expectedIdentity, $authenticatedToken->getIdentity());
        $this->assertEquals($expectedAttributes, $authenticatedToken->getAttributes());
        $this->assertSame($authenticatedToken->getUser(), $expectedUserMock);

        $roles = $authenticatedToken->getRoles();
        $this->assertInternalType('array', $roles);
        $this->assertCount(2, $roles);

        $this->assertEquals('foo', $roles[0]->getRole());
        $this->assertEquals('bar', $roles[1]->getRole());
    }

    /**
     * @test
     */
    public function shouldCreateAuthenticatedTokenUsingUserFromTokenAndCallPostAuthCheck()
    {
        $providerKey = 'main';

        $userMock = $this->createUserMock();
        $userMock
            ->expects($this->any())
            ->method('getRoles')
            ->will($this->returnValue(array()))
        ;

        $userCheckerMock = $this->createUserCheckerMock();
        $userCheckerMock
            ->expects($this->once())
            ->method('checkPostAuth')
            ->with($userMock)
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $this->createUserProviderMock(),
            $userCheckerMock
        );

        $token = new OpenIdToken($providerKey, 'identity');
        $token->setUser($userMock);

        $authenticatedToken = $authProvider->authenticate($token);

        $this->assertInstanceOf('Fp\OpenIdBundle\Security\Core\Authentication\Token\OpenIdToken', $authenticatedToken);
        $this->assertSame($authenticatedToken->getUser(), $userMock);
    }

    /**
     * @test
     */
    public function shouldCreateAuthenticatedTokenUsingIdentityIfUserProviderNotSet()
    {
        $providerKey = 'main';
        $expectedIdentity = $expectedUser = 'the_identity';
        $expectedAttributes = array('foo' => 'foo', 'bar' => 'bar_val');

        $authProvider = new OpenIdAuthenticationProvider($providerKey);

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser('');
        $token->setAttributes($expectedAttributes);

        $authenticatedToken = $authProvider->authenticate($token);

        $this->assertInstanceOf('Fp\OpenIdBundle\Security\Core\Authentication\Token\OpenIdToken', $authenticatedToken);
        $this->assertNotSame($token, $authenticatedToken);
        $this->assertTrue($authenticatedToken->isAuthenticated());
        $this->assertEquals($expectedIdentity, $authenticatedToken->getIdentity());
        $this->assertEquals($expectedUser, $authenticatedToken->getUser());
        $this->assertEquals($expectedAttributes, $authenticatedToken->getAttributes());
        $this->assertEquals(array(), $authenticatedToken->getRoles());
    }

    /**
     * @test
     */
    public function shouldCreateAuthenticatedTokenUsingUserProviderAndSearchByIdentity()
    {
        $providerKey = 'main';
        $expectedIdentity = 'the_identity';
        $expectedAttributes = array('foo' => 'foo', 'bar' => 'bar_val');

        $expectedUserMock = $this->createUserMock();
        $expectedUserMock
            ->expects($this->any())
            ->method('getRoles')
            ->will($this->returnValue(array('foo', 'bar')))
        ;

        $userProviderMock = $this->createUserProviderMock();
        $userProviderMock
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->with($expectedIdentity)
            ->will($this->returnValue($expectedUserMock))
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userProviderMock,
            $this->createUserCheckerMock()
        );

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser('');
        $token->setAttributes($expectedAttributes);

        $authenticatedToken = $authProvider->authenticate($token);

        $this->assertInstanceOf('Fp\OpenIdBundle\Security\Core\Authentication\Token\OpenIdToken', $authenticatedToken);
        $this->assertNotSame($token, $authenticatedToken);
        $this->assertTrue($authenticatedToken->isAuthenticated());
        $this->assertEquals($expectedIdentity, $authenticatedToken->getIdentity());
        $this->assertEquals($expectedUserMock, $authenticatedToken->getUser());
        $this->assertEquals($expectedAttributes, $authenticatedToken->getAttributes());

        $roles = $authenticatedToken->getRoles();
        $this->assertInternalType('array', $roles);
        $this->assertCount(2, $roles);

        $this->assertEquals('foo', $roles[0]->getRole());
        $this->assertEquals('bar', $roles[1]->getRole());
    }

    /**
     * @test
     *
     * @expectedException Symfony\Component\Security\Core\Exception\AuthenticationServiceException
     * @expectedExceptionMessage User provider did not return an implementation of user interface.
     */
    public function throwIfUserProviderReturnNotUserInstance()
    {
        $providerKey = 'main';
        $expectedIdentity = 'the_identity';

        $userProviderMock = $this->createUserProviderMock();
        $userProviderMock
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->with($expectedIdentity)
            ->will($this->returnValue('not-valid-user-instance'))
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userProviderMock,
            $this->createUserCheckerMock()
        );

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser('');

        $authProvider->authenticate($token);
    }

    /**
     * @test
     *
     * @expectedException Symfony\Component\Security\Core\Exception\UsernameNotFoundException
     * @expectedExceptionMessage Cannot find user by openid identity
     */
    public function shouldNotCreateUserIfNotExistIfFlagNotSet()
    {
        $providerKey = 'main';
        $expectedIdentity = 'the_identity';

        $userManagerMock = $this->createUserManagerMock();
        $userManagerMock
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->with($expectedIdentity)
            ->will($this->throwException(new UsernameNotFoundException('Cannot find user by openid identity')))
        ;
        $userManagerMock
            ->expects($this->never())
            ->method('createUserFromIdentity')
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userManagerMock,
            $this->createUserCheckerMock(),
            $createIfNotExist = false
        );

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser('');

        $authProvider->authenticate($token);
    }

    /**
     * @test
     */
    public function shouldCreateAuthenticatedTokenUsingUserManagerCreateFromIdentityMethod()
    {
        $providerKey = 'main';
        $expectedIdentity = 'the_identity';
        $expectedAttributes = array('foo' => 'foo', 'bar' => 'bar_val');

        $expectedUserMock = $this->createUserMock();
        $expectedUserMock
            ->expects($this->any())
            ->method('getRoles')
            ->will($this->returnValue(array('foo', 'bar')))
        ;

        $userManagerMock = $this->createUserManagerMock();
        $userManagerMock
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->with($expectedIdentity)
            ->will($this->throwException(new UsernameNotFoundException('Cannot find user by openid identity')))
        ;
        $userManagerMock
            ->expects($this->once())
            ->method('createUserFromIdentity')
            ->with($expectedIdentity)
            ->will($this->returnValue($expectedUserMock))
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userManagerMock,
            $this->createUserCheckerMock(),
            $createIfNotExist = true
        );

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser('');
        $token->setAttributes($expectedAttributes);

        $authenticatedToken = $authProvider->authenticate($token);

        $this->assertInstanceOf('Fp\OpenIdBundle\Security\Core\Authentication\Token\OpenIdToken', $authenticatedToken);
        $this->assertNotSame($token, $authenticatedToken);
        $this->assertTrue($authenticatedToken->isAuthenticated());
        $this->assertEquals($expectedIdentity, $authenticatedToken->getIdentity());
        $this->assertEquals($expectedUserMock, $authenticatedToken->getUser());
        $this->assertEquals($expectedAttributes, $authenticatedToken->getAttributes());

        $roles = $authenticatedToken->getRoles();
        $this->assertInternalType('array', $roles);
        $this->assertCount(2, $roles);

        $this->assertEquals('foo', $roles[0]->getRole());
        $this->assertEquals('bar', $roles[1]->getRole());
    }

    /**
     * @test
     *
     * @expectedException Symfony\Component\Security\Core\Exception\AuthenticationServiceException
     * @expectedExceptionMessage User provider did not return an implementation of user interface.
     */
    public function throwIfUserManagerCreateNotUserInstance()
    {
        $providerKey = 'main';
        $expectedIdentity = 'the_identity';

        $userManagerMock = $this->createUserManagerMock();
        $userManagerMock
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->with($expectedIdentity)
            ->will($this->throwException(new UsernameNotFoundException('Cannot find user by openid identity')))
        ;
        $userManagerMock
            ->expects($this->once())
            ->method('createUserFromIdentity')
            ->with($expectedIdentity)
            ->will($this->returnValue('not-a-user-instance'))
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userManagerMock,
            $this->createUserCheckerMock(),
            $createIfNotExist = true
        );

        $token = new OpenIdToken($providerKey, $expectedIdentity);
        $token->setUser('');

        $authProvider->authenticate($token);
    }

    /**
     * @test
     */
    public function shouldWrapAnyThrownExceptionsAsAuthenticatedServiceException()
    {
        $providerKey = 'main';
        $expectedPreviousException = new \Exception(
            $expectedMessage = 'Something goes wrong',
            $expectedCode = 23
        );

        $userProviderMock = $this->createUserProviderMock();
        $userProviderMock
            ->expects($this->once())
            ->method('loadUserByUsername')
            ->will($this->throwException($expectedPreviousException))
        ;

        $authProvider = new OpenIdAuthenticationProvider(
            $providerKey,
            $userProviderMock,
            $this->createUserCheckerMock()
        );

        $token = new OpenIdToken($providerKey, 'identity');
        $token->setUser('');

        try {
            $authProvider->authenticate($token);
        } catch (AuthenticationServiceException $e) {
            $this->assertSame($expectedPreviousException, $e->getPrevious());
            $this->assertEquals($expectedMessage, $e->getMessage());
            $this->assertEquals($expectedCode, $e->getCode());
            $this->assertNull($e->getExtraInformation());

            return;
        }

        $this->fail('Expected exception: AuthenticationServiceException was not thrown');
    }

    protected function createUserProviderMock()
    {
        return $this->getMock('Symfony\Component\Security\Core\User\UserProviderInterface');
    }

    protected function createUserCheckerMock()
    {
        return $this->getMock('Symfony\Component\Security\Core\User\UserCheckerInterface');
    }

    protected function createUserManagerMock()
    {
        return $this->getMock('Fp\OpenIdBundle\Tests\Security\Core\Authentication\UserManager');
    }

    protected function createUserMock()
    {
        return $this->getMock('Symfony\Component\Security\Core\User\UserInterface');
    }
}

abstract class UserManager implements UserProviderInterface, UserManagerInterface
{

}