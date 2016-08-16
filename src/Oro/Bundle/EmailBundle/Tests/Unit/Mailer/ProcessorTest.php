<?php

namespace Oro\Bundle\EmailBundle\Tests\Unit\Mailer;

use Symfony\Component\PropertyAccess\PropertyAccess;

use Oro\Bundle\EmailBundle\Entity\EmailFolder;
use Oro\Bundle\EmailBundle\Entity\InternalEmailOrigin;
use Oro\Bundle\EmailBundle\Tools\EmailAddressHelper;
use Oro\Bundle\EmailBundle\Form\Model\Email;
use Oro\Bundle\EmailBundle\Mailer\Processor;
use Oro\Bundle\EmailBundle\Tests\Unit\Fixtures\Entity\TestUser;
use Oro\Bundle\EmailBundle\Model\FolderType;
use Oro\Bundle\EmailBundle\Tools\EmailOriginHelper;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\SecurityBundle\Encoder\Mcrypt;
use Oro\Bundle\EntityConfigBundle\DependencyInjection\Utils\ServiceLink;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Oro\Bundle\OrganizationBundle\Entity\Organization;

/**
 * Class ProcessorTest
 *
 * @package Oro\Bundle\EmailBundle\Tests\Unit\Mailer
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class ProcessorTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $em;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $doctrineHelper;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $mailer;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $emailEntityBuilder;

    /** @var  \PHPUnit_Framework_MockObject_MockObject */
    protected $emailActivityManager;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $dispatcher;

    /** @var Processor */
    protected $emailProcessor;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $userEmailOrigin;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $mailerTransport;

    /** @var EmailOriginHelper|\PHPUnit_Framework_MockObject_MockObject */
    protected $emailOriginHelper;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $emailOwnerProvider;

    /** @var ServiceLink|\PHPUnit_Framework_MockObject_MockObject */
    protected $securityFacadeLink;

    /** @var SecurityFacade|\PHPUnit_Framework_MockObject_MockObject */
    protected $securityFacade;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp()
    {
        $this->em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->doctrineHelper = $this->getMockBuilder('Oro\Bundle\EntityBundle\ORM\DoctrineHelper')
            ->disableOriginalConstructor()
            ->getMock();
        $this->mailer = $this->getMockBuilder('Oro\Bundle\EmailBundle\Mailer\DirectMailer')
             ->disableOriginalConstructor()
            ->getMock();
        $this->mailerTransport = $this->getMockBuilder('\Swift_Transport_EsmtpTransport')
            ->disableOriginalConstructor()
            ->getMock();
        $this->mailer->expects($this->any())
            ->method('getTransport')
            ->will($this->returnValue($this->mailerTransport));
        $this->emailEntityBuilder = $this->getMockBuilder('Oro\Bundle\EmailBundle\Builder\EmailEntityBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailOwnerProvider = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Provider\EmailOwnerProvider')
            ->disableOriginalConstructor()
            ->getMock();
        $this->dispatcher = $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcherInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $this->securityFacade = $this->getMockBuilder('Oro\Bundle\SecurityBundle\SecurityFacade')
            ->setMethods(['getLoggedUser', 'getOrganization'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->emailActivityManager =
            $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Manager\EmailActivityManager')
                ->disableOriginalConstructor()
                ->getMock();

        $this->emailOriginHelper = $this->getMockBuilder('Oro\Bundle\EmailBundle\Tools\EmailOriginHelper')
            ->setMethods(['setEmailModel', 'findEmailOrigin'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->userEmailOrigin = $this->getMockBuilder('Oro\Bundle\ImapBundle\Entity\UserEmailOrigin')
                ->disableOriginalConstructor()
                ->getMock();

        $this->userEmailOrigin->expects($this->any())
            ->method('getSmtpHost')
            ->will($this->returnValue('abc'));

        $this->userEmailOrigin->expects($this->any())
            ->method('getSmtpPort')
            ->will($this->returnValue(25));

        $this->securityFacadeLink = $this
            ->getMockBuilder('Oro\Bundle\EntityConfigBundle\DependencyInjection\Utils\ServiceLink')
            ->setMethods(['getService'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->securityFacadeLink->expects($this->any())
            ->method('getService')
            ->will($this->returnValue($this->securityFacade));

        $this->securityFacade->expects($this->any())
            ->method('getOrganization')
            ->will($this->returnValue($this->getTestOrganization()));

        $this->doctrineHelper->expects($this->any())
            ->method('getEntityManager')
            ->with('OroEmailBundle:Email')
            ->will($this->returnValue($this->em));

        $folder = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailFolder')
            ->disableOriginalConstructor()
            ->getMock();
        $this->userEmailOrigin->expects($this->any())
            ->method('getFolder')
            ->with(FolderType::SENT)
            ->will($this->returnValue($folder));

        $emailOriginRepo = $this->getMockBuilder('Doctrine\ORM\EntityRepository')
            ->disableOriginalConstructor()
            ->getMock();
        $emailOriginRepo->expects($this->any())
            ->method('findOneBy')
            ->with(['internalName' => InternalEmailOrigin::BAP])
            ->will($this->returnValue($this->userEmailOrigin));
        $this->em->expects($this->any())
            ->method('getRepository')
            ->with('OroEmailBundle:InternalEmailOrigin')
            ->will($this->returnValue($emailOriginRepo));

        $this->emailProcessor = new Processor(
            $this->doctrineHelper,
            $this->mailer,
            new EmailAddressHelper(),
            $this->emailEntityBuilder,
            $this->emailOwnerProvider,
            $this->emailActivityManager,
            $this->securityFacadeLink,
            $this->dispatcher,
            new Mcrypt(),
            $this->emailOriginHelper
        );
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Sender can not be empty
     */
    public function testProcessEmptyFromException()
    {
        $this->mailer->expects($this->never())
            ->method('createMessage');
        $this->mailer->expects($this->never())
            ->method('send');

        $this->emailProcessor->process($this->createEmailModel([]));
    }

    /**
     * @param array  $data
     * @param string $exception
     * @param string $exceptionMessage
     *
     * @dataProvider invalidModelDataProvider
     */
    public function testProcessEmptyToException(array $data, $exception, $exceptionMessage)
    {
        $this->mailer->expects($this->never())
            ->method('createMessage');
        $this->mailer->expects($this->never())
            ->method('send');

        $this->setExpectedException($exception, $exceptionMessage);
        $this->emailProcessor->process($this->createEmailModel($data));
    }

    public function invalidModelDataProvider()
    {
        return [
            [[], '\InvalidArgumentException', 'Sender can not be empty'],
            [['from' => 'test@test.com'], '\InvalidArgumentException', 'Recipient can not be empty'],
        ];
    }

    public function testProcessSend()
    {
        $message = new \Swift_Message();
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($message)
            ->will($this->returnValue(true));
        $emailOrigin = $this->userEmailOrigin;

        $oldMessageId = $message->getId();
        $this->emailProcessor->processSend($message, $emailOrigin);
        $messageId = $message->getId();

        $this->assertEquals($oldMessageId, $messageId);
    }

    /**
     * @expectedException \Swift_SwiftException
     * @expectedExceptionMessage An email was not delivered.
     */
    public function testProcessSendFailException()
    {
        $message = $this->getMockForAbstractClass('\Swift_Message');
        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($message)
            ->will($this->returnValue(false));

        $model = $this->createEmailModel(
            [
                'from'    => 'test@test.com',
                'to'      => ['test2@test.com'],
                'subject' => 'test',
                'body'    => 'test body'
            ]
        );
        $this->emailProcessor->process($model);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The $addresses argument must be a string or a list of strings (array or Iterator)
     */
    public function testProcessAddressException()
    {
        $message = $this->getMockForAbstractClass('\Swift_Message');
        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->never())
            ->method('send');

        $model = $this->createEmailModel(
            [
                'from' => new \stdClass(),
                'to' => [new \stdClass()],
            ]
        );
        $this->emailProcessor->process($model);
    }

    /**
     * @dataProvider messageDataProvider
     * @param array $data
     * @param array $expectedMessageData
     * @param bool  $needConverting
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testProcess($data, $expectedMessageData, $needConverting = false)
    {
        $message = new \Swift_Message();
        $this->emailOriginHelper->expects(self::once())
            ->method('findEmailOrigin')
            ->will(self::returnValue($this->userEmailOrigin));

        $this->userEmailOrigin->expects($this->once())
            ->method('isSmtpConfigured')
            ->will($this->returnValue(true));

        $this->mailer->expects($this->once())
            ->method('createMessage')
            ->will($this->returnValue($message));
        $this->mailer->expects($this->once())
            ->method('send')
            ->with($message)
            ->will($this->returnValue(true));

        $oldMessageId = $message->getId();

        $emailUser = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailUser')
            ->setMethods(['addFolder', 'getEmail'])
            ->getMock();
        $emailUser->expects($this->once())
            ->method('addFolder');
        $email = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\Email')
            ->disableOriginalConstructor()
            ->getMock();
        $emailUser->expects($this->any())
            ->method('getEmail')
            ->willReturn($email);
        $this->emailEntityBuilder->expects($this->once())
            ->method('emailUser')
            ->with(
                $data['subject'],
                $data['from'],
                $data['to'],
                $this->isInstanceOf('DateTime'),
                $this->isInstanceOf('DateTime'),
                $this->isInstanceOf('DateTime'),
                \Oro\Bundle\EmailBundle\Entity\Email::NORMAL_IMPORTANCE,
                $data['cc'],
                $data['bcc']
            )
            ->willReturn($emailUser);

        $body = $this->getMockBuilder('Oro\Bundle\EmailBundle\Entity\EmailBody')
            ->disableOriginalConstructor()
            ->getMock();
        $this->emailEntityBuilder->expects($this->once())
            ->method('body')
            ->will($this->returnValue($body));

        $batch = $this->getMock('Oro\Bundle\EmailBundle\Builder\EmailEntityBatchInterface');
        $this->emailEntityBuilder->expects($this->once())
            ->method('getBatch')
            ->will($this->returnValue($batch));
        $batch->expects($this->once())
            ->method('persist')
            ->with($this->identicalTo($this->em));
        $this->em->expects($this->once())->method('flush');

        $email->expects($this->any())
            ->method('getEmailBody')
            ->willReturn($body);

        if (!empty($data['entityClass']) && !empty($data['entityClass'])) {
            $targetEntity = new TestUser();
            $this->doctrineHelper->expects($this->exactly(0))
                ->method('getEntity')
                ->with($data['entityClass'], $data['entityId'])
                ->will($this->returnValue($targetEntity));
            $this->emailActivityManager->expects($this->exactly(0))
                ->method('addAssociation')
                ->with($this->identicalTo($email), $this->identicalTo($targetEntity));
        }

        $model = $this->createEmailModel($data);

        $this->assertSame($emailUser, $this->emailProcessor->process($model));
        $this->assertEquals($expectedMessageData['from'], [$model->getFrom()]);
        $this->assertEquals($data['cc'], $model->getCc());
        $this->assertEquals($data['bcc'], $model->getBcc());
        $this->assertEquals($expectedMessageData['subject'], $model->getSubject());
        $this->assertEquals($oldMessageId, $message->getId());

        if ($needConverting) {
            $id = $model->getAttachments()->first()->getEmailAttachment()->getEmbeddedContentId();
            $this->assertEquals(sprintf($expectedMessageData['body'], 'cid:' . $id), $message->getBody());
        } else {
            $this->assertEquals($expectedMessageData['body'], $model->getBody());
            $this->assertEquals($expectedMessageData['body'], $message->getBody());
        };
    }

    protected function getTestOrganization()
    {
        $organization = new Organization();
        $organization->setId(1);

        return $organization;
    }

    protected function getTestOrigin()
    {
        $outboxFolder = new EmailFolder();
        $outboxFolder
            ->setType(FolderType::SENT)
            ->setName(FolderType::SENT)
            ->setFullName(FolderType::SENT);

        $origin = new InternalEmailOrigin();
        $origin
            ->setName('BAP_User_1')
            ->addFolder($outboxFolder)
            ->setOwner($this->getTestUser())
            ->setOrganization($this->getTestOrganization());

        return $origin;
    }

    /**
     * @return array
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function messageDataProvider()
    {
        return [
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to@test.com'],
                    'cc' => ['Cc <cc@test.com>'],
                    'bcc' => ['Bcc <bcc@test.com>'],
                    'subject' => 'subject',
                    'body' => 'body <img width=100 src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACAQMAAAB'
                            .'IeJ9nAAAAA1BMVEX///+nxBvIAAAAAWJLR0QAiAUdSAAAAAlwSFlzAAALEwAACxMBAJqcGAAAAAd0SU1FB98GEA'
                            .'grLyNXN+0AAAAmaVRYdENvbW1lbnQAAAAAAENyZWF0ZWQgd2l0aCBHSU1QIG9uIGEgTWFjleRfWwAAAAxJREFUCN'
                            .'djYGBgAAAABAABJzQnCgAAAABJRU5ErkJggg==" height="100"/>',
                    'type' => 'html'
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to@test.com'],
                    'cc' => ['cc@test.com' => 'Cc'],
                    'bcc' => ['bcc@test.com' => 'Bcc'],
                    'subject' => 'subject',
                    'body' => 'body <img width=100 src="%s" height="100"/>',
                    'type' => 'text/html'
                ],
                true
            ],
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to@test.com'],
                    'cc' => ['Cc <cc@test.com>'],
                    'bcc' => ['Bcc <bcc@test.com>'],
                    'subject' => 'subject',
                    'body' => 'body <img src="http://sth.com/cool-image.png">',
                    'type' => 'html'
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to@test.com'],
                    'cc' => ['cc@test.com' => 'Cc'],
                    'bcc' => ['bcc@test.com' => 'Bcc'],
                    'subject' => 'subject',
                    'body' => 'body <img src="http://sth.com/cool-image.png">',
                    'type' => 'text/html'
                ],
            ],
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to@test.com'],
                    'cc' => [],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body',
                    'type' => 'html'
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to@test.com'],
                    'cc' => [],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body',
                    'type' => 'text/html'
                ]
            ],
            [
                [
                    'from' => 'Test <from@test.com>',
                    'to' => ['To <to@test.com>', 'to2@test.com'],
                    'cc' => ['Cc3 <cc3@test.com>', 'cc4@test.com'],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body'
                ],
                [
                    'from' => ['Test <from@test.com>'],
                    'to' => ['to@test.com' => 'To', 'to2@test.com'],
                    'cc' => ['cc3@test.com' => 'Cc3', 'cc4@test.com'],
                    'bcc' => [],
                    'subject' => 'subject',
                    'body' => 'body'
                ]
            ],
            [
                [
                    'from' => 'from@test.com',
                    'to' => ['to1@test.com', 'to1@test.com', 'to2@test.com'],
                    'cc' => [],
                    'bcc' => ['bcc3@test.com', 'bcc4@test.com'],
                    'subject' => 'subject',
                    'body' => 'body',
                    'entityClass' => 'Entity\Target',
                    'entityId' => 123
                ],
                [
                    'from' => ['from@test.com'],
                    'to' => ['to1@test.com', 'to1@test.com', 'to2@test.com'],
                    'cc' => [],
                    'bcc' => ['bcc3@test.com', 'bcc4@test.com'],
                    'subject' => 'subject',
                    'body' => 'body'
                ]
            ],
        ];
    }

    protected function getTestUser()
    {
        $user = new User();
        $user->setId(1);
        $user->setEmail('test_user@test.com');
        $user->setSalt('1fqvkjskgry8s8cs400840c0ok8ggck');

        return $user;
    }

    protected function createEmailModel($data)
    {
        $email = new Email();
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        foreach ($data as $key => $value) {
            $propertyAccessor->setValue($email, $key, $value);
        }
        return $email;
    }
}
