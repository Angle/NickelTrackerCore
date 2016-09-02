<?php

namespace Angle\NickelTracker\CoreBundle\Service;

use Doctrine\Bundle\DoctrineBundle\Registry as Doctrine;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\DBALException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactory;

use Angle\Common\UtilityBundle\Random\RandomUtility;

use Angle\NickelTracker\CoreBundle\Entity\User;
use Angle\NickelTracker\CoreBundle\Entity\Account;
use Angle\NickelTracker\CoreBundle\Entity\Category;

class NickelTrackerService
{
    protected $doctrine;
    protected $em;

    protected $tokenStorage;
    protected $encoderFactory;

    /** @var User $user */
    protected $user;

    // Administrator Mode
    protected $adminMode = false;

    // Error Handling
    protected $errorType;
    protected $errorCode;
    protected $errorMessage;

    public function __construct(Doctrine $doctrine, TokenStorageInterface $tokenStorage, EncoderFactory $encoderFactory)
    {
        $this->doctrine = $doctrine;
        $this->em = $this->doctrine->getManager();

        // Attempt to load a session user
        $token = $tokenStorage->getToken();
        if ($token instanceof TokenInterface) {
            $this->user = $token->getUser();
        }

        $this->encoderFactory = $encoderFactory;
    }

    /**
     * Operate on NickelTracker as an Admin with no user record attached.
     * @param bool $enable
     */
    public function enableAdminMode($enable)
    {
        if ($enable) {
            // Destroy the session user and enable the flag
            $this->user = null;
            $this->adminMode = true;
        } else {
            $this->adminMode = false;
        }
    }

    /**
     * Get the last error that has occurred as an array. If no error has occurred, return null.
     * @return array|null
     */
    public function getError()
    {
        if ($this->errorType) {
            return array(
                'type' => $this->errorType,
                'code' => $this->errorCode,
                'message' => $this->errorMessage,
            );
        } else {
            return null;
        }
    }

    /**
     * // Attempt to flush to the database
     * @return bool
     */
    public function flush()
    {
        try {
            $this->em->flush();
        } catch (DBALException $e) {
            $this->errorType = 'Doctrine';
            $this->errorCode = $e->getCode();
            $this->errorMessage = $e->getMessage();
            return false;
        }

        return true;
    }


    #####################################################################################################
    ###
    ###   USER METHODS (ADMIN MODE ONLY)
    ###

    /**
     * Create a new User
     *
     * @param string $email User's valid email address
     * @param string $fullName User's full name
     * @param string $password User's un-hashed password
     * @return bool
     */
    public function createUser($email, $fullName, $password)
    {
        if (!$this->adminMode) {
            throw new \RuntimeException('Attempting to execute an Admin command without privileges');
        }

        // Check email format
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { // email is invalid
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Invalid user email provided';
            return false;
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setFullName($fullName);
        $user->setRole('ROLE_NT_USER');
        $this->em->persist($user);

        /* @var \Symfony\Component\Security\Core\Encoder\PasswordEncoderInterface $encoder */
        $encoder = $this->encoderFactory->getEncoder($user);
        $encodedPassword = $encoder->encodePassword($password, $user->getSalt());
        $user->setPassword($encodedPassword);

        // Create base accounts
        $account = new Account();
        $account->setType(Account::TYPE_CASH);
        $account->setName('Cash');
        $account->setUserId($user);
        $this->em->persist($account);

        $account = new Account();
        $account->setType(Account::TYPE_DEBIT);
        $account->setName('Debit (Bank)');
        $account->setUserId($user);
        $this->em->persist($account);

        $account = new Account();
        $account->setType(Account::TYPE_CREDIT);
        $account->setName('Credit Card');
        $account->setUserId($user);
        $this->em->persist($account);

        // Create base categories
        $categories = array('Groceries', 'Restaurants', 'Gas', 'Entertainment', 'Party', 'Services');
        foreach ($categories as $c) {
            $category = new Category();
            $category->setName($c);
            $category->setUserId($user);
            $this->em->persist($category);
        }

        return $this->flush();
    }


    public function changeUserPassword(User $user, $oldPassword, $newPassword)
    {
        if (!$this->adminMode) {
            throw new \RuntimeException('Attempting to execute an Admin command without privileges');
        }

        // Check if the user already exists in the database
        if (!$user->getUserId()) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Cannot operate on non-persisted objects';
            return false;
        }

        /* @var \Symfony\Component\Security\Core\Encoder\PasswordEncoderInterface $encoder */
        $encoder = $this->encoderFactory->getEncoder($user);

        // First check if the old password matches the records
        if (!$encoder->isPasswordValid($user->getPassword(), $oldPassword, $user->getSalt())) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'User password mismatch';
            return false;
        }

        // Now update the password
        $user->refreshSalt();
        $encodedNewPassword = $encoder->encodePassword($newPassword, $user->getSalt());
        $user->setPassword($encodedNewPassword);

        $this->em->persist($user);

        return $this->flush();
    }

    public function disableUser(User $user)
    {
        if (!$this->adminMode) {
            throw new \RuntimeException('Attempting to execute an Admin command without privileges');
        }

        // Check if the user already exists in the database
        if (!$user->getUserId()) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Cannot operate on non-persisted objects';
            return false;
        }

        $user->setIsActive(false);
        $this->em->persist($user);
        return $this->flush();
    }

    public function enableUser(User $user)
    {
        if (!$this->adminMode) {
            throw new \RuntimeException('Attempting to execute an Admin command without privileges');
        }

        // Check if the user already exists in the database
        if (!$user->getUserId()) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Cannot operate on non-persisted objects';
            return false;
        }

        $user->setIsActive(true);
        $this->em->persist($user);
        return $this->flush();
    }


    #####################################################################################################
    ###
    ###   ACCOUNT METHODS
    ###

    /**
     * Return an ArrayCollection of the User's accounts
     * @return ArrayCollection
     */
    public function loadAccounts()
    {
        if (!$this->user) {
            throw new \RuntimeException('Session user was not found');
        }

        // Load all user accounts
        $repository = $this->doctrine->getRepository(Account::class);
        $accounts = $repository->findBy(array(
            'userId'    => $this->user->getUserId(),
            'deleted'   => false,
        ));

        return $accounts;
    }

    /**
     * Load a single account
     *
     * @param int $id Account ID
     * @return Account|false
     */
    public function loadAccount($id)
    {
        if (!$this->user) {
            throw new \RuntimeException('Session user was not found');
        }

        // Attempt to load the Account
        $repository = $this->doctrine->getRepository(Account::class);
        /** @var Account $account */
        $account = $repository->findOneBy(array(
            'userId'    => $this->user->getUserId(),
            'accountId' => $id,
            'deleted'   => false,
        ));

        if (!$account) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Account not found';
            return false;
        }

        return $account;
    }

    /**
     * Create a new account for the user
     *
     * @param int $type Account type
     * @param string $name Account name
     * @param float $creditLimit Account's credit limit (only used for Credit accounts)
     * @return int|false AccountID created
     */
    public function createAccount($type, $name, $creditLimit=null)
    {
        if (!$this->user) {
            throw new \RuntimeException('Session user was not found');
        }

        // Check if the user already has an account with that name
        $repository = $this->doctrine->getRepository(Account::class);
        $accounts = $repository->findBy(array(
            'userId'    => $this->user->getUserId(),
            'name'      => $name,
            'deleted'   => false
        ));

        if (!empty($accounts)) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Cannot have two accounts with the same name';
            return false;
        }

        if (!array_key_exists($type, Account::getAvailableTypes())) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Invalid account type provided';
            return false;
        }

        $account = new Account();
        $account->setType($type);
        $account->setName($name);
        $account->setUserId($this->user);
        $account->setCreditLimit($creditLimit);
        $this->em->persist($account);

        if (!$this->flush()) {
            return false;
        } else {
            return $account->getAccountId();
        }
    }

    /**
     * Delete a user's account
     *
     * @param int $id Account ID
     * @return bool
     */
    public function deleteAccount($id)
    {
        if (!$this->user) {
            throw new \RuntimeException('Session user was not found');
        }

        // Attempt to load the Account
        $repository = $this->doctrine->getRepository(Account::class);
        /** @var Account $account */
        $account = $repository->findOneBy(array(
            'userId'    => $this->user->getUserId(),
            'accountId' => $id,
            'deleted'   => false,
        ));

        if (!$account) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Account not found';
            return false;
        }

        $account->setDeleted(true);

        // Also change the account name so that a new one can be created with the same name
        $account->setName($account->getName() . '_DELETED_' . RandomUtility::generateString(6));

        return $this->flush();
    }

    /**
     * Change the name of a user's account
     *
     * @param int $id Account ID
     * @param string $newName Desired new name for the account
     * @return bool
     */
    public function changeAccountName($id, $newName)
    {
        if (!$this->user) {
            throw new \RuntimeException('Session user was not found');
        }

        // Check if the user already has an account with that name
        $repository = $this->doctrine->getRepository(Account::class);
        $accounts = $repository->findBy(array(
            'userId'    => $this->user->getUserId(),
            'name'      => $newName,
            'deleted'   => false
        ));

        if (!empty($accounts)) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Cannot have two accounts with the same name';
            return false;
        }

        // Attempt to load the Account
        /** @var Account $account */
        $account = $repository->findOneBy(array(
            'userId'    => $this->user->getUserId(),
            'accountId' => $id,
            'deleted'   => false,
        ));

        if (!$account) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Account not found';
            return false;
        }

        $account->setName($newName);

        return $this->flush();
    }

    /**
     * Change the Credit Limit for the account
     *
     * @param int $id Account ID
     * @param float $newLimit Desired new limit for the account
     * @return bool
     */
    public function changeAccountCreditLimit($id, $newLimit)
    {
        if (!$this->user) {
            throw new \RuntimeException('Session user was not found');
        }

        // Attempt to load the Account
        $repository = $this->doctrine->getRepository(Account::class);
        /** @var Account $account */
        $account = $repository->findOneBy(array(
            'userId'    => $this->user->getUserId(),
            'accountId' => $id,
            'deleted'   => false,
        ));

        if (!$account) {
            $this->errorType = 'NickelTracker';
            $this->errorCode = 1;
            $this->errorMessage = 'Account not found';
            return false;
        }

        $account->setCreditLimit($newLimit);

        return $this->flush();
    }

}