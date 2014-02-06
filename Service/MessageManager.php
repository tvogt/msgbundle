<?php

namespace Calitarus\MessagingBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\Common\Collections\Criteria;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Doctrine\Common\Collections\ArrayCollection;


use BM2\SiteBundle\Service\AppState;
use BM2\SiteBundle\Entity\Character;

use Calitarus\MessagingBundle\Entity\Conversation;
use Calitarus\MessagingBundle\Entity\ConversationMetadata;
use Calitarus\MessagingBundle\Entity\Message;
use Calitarus\MessagingBundle\Entity\MessageMetadata;
use Calitarus\MessagingBundle\Entity\MessageRelation;
use Calitarus\MessagingBundle\Entity\User;
use Calitarus\MessagingBundle\Entity\Right;


class MessageManager {

	protected $em;
	protected $appstate;

	protected $user = null;

	public function __construct(EntityManager $em, AppState $appstate) {
		$this->em = $em;
		$this->appstate = $appstate;
	}

	public function getMsgUser(Character $char) {
		$user = $this->em->getRepository('MsgBundle:User')->findOneBy(array('app_user'=>$char));
		if ($user==null) {
			// messsaging user entity doesn't exist, create it and set the reference
			$user = new User;
			$user->setAppUser($char);
			$this->em->persist($user);
			$this->em->flush($user);
		}
		return $user;
	}

	public function getCurrentUser() {
		if ($this->user==null) {
			$character = $this->appstate->getCharacter();
			$this->user = $this->getMsgUser($character);
		}
		return $this->user;
	}

	public function findTopics(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }

		$query = $this->em->createQuery('SELECT c FROM MsgBundle:Conversation c JOIN c.metadata m JOIN m.user u WHERE c.parent IS NULL and u = :me');
		$query->setParameter('me', $user);
		return $query->getResult();
	}


	public function getContactsList(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }

		$query = $this->em->createQuery('SELECT DISTINCT u FROM MsgBundle:User u JOIN u.conversations_metadata m1 JOIN m1.conversation c JOIN c.metadata m2 where m2.user = :me AND u != :me');
		$query->setParameter('me', $user);
		return $query->getResult();
	}

	public function getConversation(ConversationMetadata $m) {
		// TODO: check if this works for multiple periods (I think it will)
		$qb = $this->em->createQueryBuilder();
		$qb->select('c, msg, meta')
			->from('MsgBundle:Conversation', 'c')
			->join('c.metadata', 'm')
			->join('c.messages', 'msg')
			->leftJoin('msg.metadata', 'meta')
			->where('m = :m')->setParameter('m', $m)
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('msg.depth', 0),
				$qb->expr()->gt('msg.ts', 'm.last_read')
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('meta.user', 'm.user'),
				$qb->expr()->isNull('meta')
			));
		if ($m->getAccessFrom()) {
			$qb->andWhere($qb->expr()->gt('msg.ts', 'm.access_from'));
		}
		if ($m->getAccessUntil()) {
			$qb->andWhere($qb->expr()->lt('msg.ts', 'm.access_until'));
		}
		$qb->orderBy('msg.ts', 'DESC');
		$query = $qb->getQuery();

		// set read status
		$m->setUnread(0)->setLastRead(new \DateTime("now"));

		return $query->getResult();
	}


	/* creation methods */
	
	public function createConversation(User $creator, $topic, Conversation $parent=null) {
		$conversation = new Conversation;
		$conversation->setTopic($topic);
		if ($parent) {
			$conversation->setParent($parent);
			$parent->addChildren($conversation);
		}
		$this->em->persist($conversation);

		$meta = new ConversationMetadata;
		$meta->setUnread(0);
		$meta->setConversation($conversation);
		$meta->setUser($creator);

		$owner = $this->em->getRepository('MsgBundle:Right')->findOneByName('owner');
		$meta->addRight($owner);

		$conversation->addMetadata($meta);
		$this->em->persist($meta);

		return $conversation;
	}

	public function newConversation(User $creator, $recipients, $topic, $content, $translate=false, Conversation $parent=null) {
		$conversation = $this->createConversation($creator, $topic, $parent);

		foreach ($recipients as $recipient) {
			$meta = new ConversationMetadata;
			$meta->setUnread(0);
			$meta->setConversation($conversation);
			$meta->setUser($recipient);
			$conversation->addMetadata($meta);
			$this->em->persist($meta);
		}

		$message = $this->writeMessage($conversation, $creator, $content, 0, $translate);
		$this->em->flush();
		return $message;
	}

	public function writeMessage(Conversation $conversation, User $author, $content, $depth=0, $translate=false) {
		$msg = new Message;
		$msg->setSender($author);
		$msg->setContent($content);
		$msg->setConversation($conversation);
		$msg->setTs(new \DateTime("now"));
		$msg->setCycle($this->appstate->getCycle());
		$msg->setDepth($depth);
		$msg->setTranslate($translate);
		$this->em->persist($msg);

		// now increment the unread counter for everyone except the author
		foreach ($conversation->getMetadata() as $reader) {
			if ($reader->getUser() != $author) {
				$reader->setUnread($reader->getUnread()+1);
			}
		}

		return $msg;
	}

	public function writeReply(Message $source, User $author, $content, $translate=false) {
		$msg = $this->writeMessage($source->getConversation(), $author, $content, $source->getDepth()+1, $translate);

		$rel = new MessageRelation;
		$rel->setType('response');
		$rel->setSource($source);
		$rel->setTarget($msg);
		$this->em->persist($rel);

		return $msg;
	}

	public function writeSplit(Message $source, User $author, $topic, $content, $translate=false) {
		// set our recipients to be identical to the ones of the old conversation
		$recipients = new ArrayCollection;
		foreach ($source->getConversation()->getMetadata() as $m) {
			if ($m->getUser() != $user) {
				$recipients->add($m->getUser());
			}
		}

		$msg = $this->newConversation($author, $recipients, $topic, $content, $translate, $source->getConversation());

		$rel = new MessageRelation;
		$rel->setType('response');
		$rel->setSource($source);
		$rel->setTarget($msg);
		$this->em->persist($rel);

		return $msg;
	}


	public function addMessage(Conversation $conversation, User $author, $content, $translate=false) {
		$msg = $this->writeMessage($conversation, $author, $content, 0, $translate);

		return $msg;
	}

}
