<?php

namespace ChangeSetTest\ObjectManager;

use PHPUnit_Framework_TestCase;
use ChangeSet\UnitOfWork\SimpleUnitOfWork;
use ChangeSet\ObjectRepository\ObjectRepositoryFactory;
use ChangeSet\ObjectLoader\ObjectLoaderFactory;
use ChangeSet\IdentityMap\IdentityMap;
use ChangeSet\ChangeSetListener\IdentityMapSynchronizer;
use ChangeSet\ObjectManager\SimpleObjectManager;
use ChangeSet\ChangeSet;
use ChangeSet\Committer\SimpleLoggingCommitter;
use Zend\EventManager\EventManager;

class ObjectManagerIntegrationTest extends PHPUnit_Framework_TestCase
{
    protected $changeSetEventManager;
    protected $changeSet;
    protected $identityMap;
    protected $identityMapSynchronizer;
    protected $unitOfWork;
    protected $objectLoaderFactory;
    protected $repositoryFactory;
    protected $objectManager;
    protected $committer;
    public function setUp()
    {
        $this->changeSetEventManager = new EventManager();
        $this->changeSet = new ChangeSet($this->changeSetEventManager);
        $this->identityMap = new IdentityMap();
        $this->identityMapSynchronizer = new IdentityMapSynchronizer($this->identityMap);
        $this->changeSetEventManager->attach($this->identityMapSynchronizer);
        $this->unitOfWork = new SimpleUnitOfWork($this->changeSet);
        $this->objectLoaderFactory = new ObjectLoaderFactory($this->unitOfWork);
        $this->repositoryFactory = new ObjectRepositoryFactory($this->unitOfWork, $this->objectLoaderFactory, $this->identityMap);
        $this->objectManager = new SimpleObjectManager($this->repositoryFactory);
        $this->committer = new SimpleLoggingCommitter();
    }

    public function testRepositoryLoad()
    {
        $listener = $this->getMock('stdClass', array('__invoke'));

        $listener->expects($this->exactly(2))->method('__invoke');
        $this->changeSetEventManager->attach('register', $listener);

        // @todo should repositories be fetched somhow differently? Maybe force per-hand instantiation?
        $repository = $this->objectManager->getRepository('stdClass');

        $this->assertInstanceOf('ChangeSet\\ObjectRepository\\ObjectRepositoryInterface', $repository);

        $object = $repository->get(123);

        $this->assertInstanceOf('stdClass', $object);
        $this->assertSame(123, $object->identity);
        $this->assertInternalType('string', $object->foo);
        $this->assertInternalType('string', $object->bar);

        $this->assertNotSame($object, $repository->get(456), 'Loads separate object for a different identifier');
        $this->assertSame($object, $repository->get(123), 'Uses identity map internally');
        
        $this->unitOfWork->commit($this->committer);
        
        $this->assertEmpty($this->committer->operations);
        
        $object->foo = 'changed!';
        
        $this->unitOfWork->commit($this->committer);
        $this->assertCount(1, $this->committer->operations);
        $this->assertSame('update', $this->committer->operations[0]['type']);
        $this->assertSame($object, $this->committer->operations[0]['object']);
    }

    public function testRepositoryAdd()
    {
        $listener = $this->getMock('stdClass', array('__invoke'));

        $listener->expects($this->exactly(2))->method('__invoke');

        $this->changeSetEventManager->attach('add', $listener);

        // @todo should repositories be fetched somhow differently? Maybe force per-hand instantiation?
        $repository = $this->objectManager->getRepository('stdClass');

        $this->assertInstanceOf('ChangeSet\\ObjectRepository\\ObjectRepositoryInterface', $repository);

        $foo = new \stdClass();
        $foo->identity = 123;
        $foo->foo = 'test';
        $foo->bar = 'baz';

        // @todo should this throw exceptions on duplicates?
        $repository->add($foo);

        $this->assertSame($foo, $repository->get(123));

        $bar = new \stdClass();
        $bar->identity = 456;
        $bar->foo = 'test2';
        $bar->bar = 'baz2';

        $repository->add($bar);

        $this->assertSame($bar, $repository->get(456));
        
        $this->unitOfWork->commit($this->committer);
        $this->assertCount(2, $this->committer->operations);
        $this->assertSame('insert', $this->committer->operations[0]['type']);
        $this->assertSame($foo, $this->committer->operations[0]['object']);
        $this->assertSame('insert', $this->committer->operations[1]['type']);
        $this->assertSame($bar, $this->committer->operations[1]['object']);
    }

    public function testRepositoryRemove()
    {
        $listener = $this->getMock('stdClass', array('__invoke'));

        $listener->expects($this->exactly(2))->method('__invoke');

        $this->changeSetEventManager->attach('remove', $listener);

        // @todo should repositories be fetched somhow differently? Maybe force per-hand instantiation?
        $repository = $this->objectManager->getRepository('stdClass');

        $this->assertInstanceOf('ChangeSet\\ObjectRepository\\ObjectRepositoryInterface', $repository);

        $foo = new \stdClass();
        $foo->identity = 123;
        $bar = new \stdClass();
        $bar->identity = 456;

        // @todo should this throw exceptions on duplicates?
        $repository->add($foo);
        $repository->add($bar);

        $repository->remove($foo);
        $repository->remove($bar);
        
        $this->unitOfWork->commit($this->committer);
        $this->assertCount(2, $this->committer->operations);
        $this->assertSame('delete', $this->committer->operations[0]['type']);
        $this->assertSame($foo, $this->committer->operations[0]['object']);
        $this->assertSame('delete', $this->committer->operations[1]['type']);
        $this->assertSame($bar, $this->committer->operations[1]['object']);
        // @todo not sure delets should already happen here...
    }
}
