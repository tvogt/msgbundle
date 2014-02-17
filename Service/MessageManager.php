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
use Calitarus\MessagingBundle\Entity\Timespan;


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
			->leftJoin('c.messages', 'msg')
			->leftJoin('msg.metadata', 'meta')
			->where('m = :m')->setParameter('m', $m)
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('msg'),
				$qb->expr()->eq('msg.depth', 0),
				$qb->expr()->gt('msg.ts', 'm.last_read')
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('meta.user', 'm.user'),
				$qb->expr()->isNull('meta')
			));

		// add time-based restriction:
		$qb->leftJoin('m.timespans', 't')
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('t'),
				$qb->expr()->andX(
					$qb->expr()->orX(
						$qb->expr()->isNull('t.access_from'),
						$qb->expr()->gte('msg.ts', 't.access_from')
					),
					$qb->expr()->orX(
						$qb->expr()->isNull('t.access_until'),
						$qb->expr()->lte('msg.ts', 't.access_until')
					)
				)
			));

		$qb->orderBy('msg.ts', 'DESC');
		$query = $qb->getQuery();

		// set read status
		$m->setUnread(0)->setLastRead(new \DateTime("now"));

		return $query->getResult();
	}

	public function getToplevelConversationsMeta(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }

		$query = $this->em->createQuery('SELECT m,c FROM MsgBundle:ConversationMetadata m JOIN m.conversation c WHERE m.user = :me AND c.parent IS NULL');
		$query->setParameter('me', $user);
		return $query->getResult();
	}


	public function leaveConversation(ConversationMetadata $meta, User $user, $ids=array()) {
		$ids[] = $meta->getId();

		$conversation = $meta->getConversation();
		foreach ($conversation->getChildren() as $child) {
			$meta = $child->findMeta($user);
			$ids = $this->leaveConversation($meta, $user, $ids);
		}

		// leaving is simply removing all my metadata		
		$query = $this->em->createQuery('SELECT m from MsgBundle:MessageMetadata m JOIN m.message msg WHERE m.user = :me AND msg.conversation = :conversation');
		$query->setParameters(array('me'=>$user, 'conversation'=>$conversation));
		foreach ($query->getResult() as $msg_meta) {
			$this->em->remove($msg_meta);
		}
		$conversation->removeMetadata($meta);
		$this->em->remove($meta);

		// if the conversation has no participants left, we can remove it:
		if ($conversation->getMetadata()->count() == 0) {
			// just remove the conversation, cascading should take care of all the messages and metadata
			$this->em->remove($conversation);
		}

		return $ids;
	}

	// this method is intended for things like a user deleting, etc.
	public function leaveAllConversations(User $user) {
		$query = $this->em->createQuery('DELETE FROM MsgBundle:MessageMetadata m WHERE m.user = :me');
		$query->setParameter('me', $user);
		$query->execute();

		$query = $this->em->createQuery('DELETE FROM MsgBundle:ConversationMetadata c WHERE c.user = :me');
		$query->setParameter('me', $user);
		$query->execute();

		$this->em->flush();

		$this->removeAbandonedConversations();
	}

	public function removeAbandonedConversations() {
		$query = $this->em->createQuery('SELECT c,count(m) as participants FROM MsgBundle:Conversation c LEFT JOIN c.metadata m GROUP BY c');
		$results = $query->getResult();

		foreach ($results as $row) {
			if ($row['participants'] == 0) {
				$this->em->remove($row[0]);
			}
		}
		$this->em->flush();
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
			if ($recipient != $creator) { // because he has already been added above
				$meta = new ConversationMetadata;
				$meta->setUnread(0);
				$meta->setConversation($conversation);
				$meta->setUser($recipient);
				$conversation->addMetadata($meta);
				$this->em->persist($meta);
			}
		}

		$message = $this->writeMessage($conversation, $creator, $content, 0, $translate);
		$this->em->flush();
		return array($meta,$message);
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

		list($meta,$msg) = $this->newConversation($author, $recipients, $topic, $content, $translate, $source->getConversation());

		$rel = new MessageRelation;
		$rel->setType('response');
		$rel->setSource($source);
		$rel->setTarget($msg);
		$this->em->persist($rel);

		return $meta;
	}


	public function addMessage(Conversation $conversation, User $author, $content, $translate=false) {
		$msg = $this->writeMessage($conversation, $author, $content, 0, $translate);

		return $msg;
	}


	/* management methods */
	
	// you might want to change $time_limit to false if you don't use it or only rarely.
	public function addParticipant(Conversation $conversation, User $participant, $time_limit=true) {
		$meta = new ConversationMetadata;
		$meta->setConversation($conversation);
		$meta->setUser($participant);
		$conversation->addMetadata($meta);
		$this->em->persist($meta);

		if ($time_limit) {
			$meta->setUnread(0);
			$span = new Timespan;
			$span->setMetadata($meta);
			$span->setAccessFrom(new \DateTime("now"));
			$meta->addTimespan($span);
			$this->em->persist($span);
		} else {
			$meta->setUnread($conversation->getMessages()->count());
		}
	}

	public function removeParticipant(Conversation $conversation, User $participant) {
		$meta = $conversation->findMeta($participant);
		if ($meta) {
			// update or generate end-of-access timespan
			if ($meta->getTimespans()->isEmpty()) {
				$span = new Timespan;
				$span->setMetadata($meta);
				$span->setAccessUntil(new \DateTime("now"));
				$meta->addTimespan($span);
				$this->em->persist($span);
			} else {
				foreach ($meta->getTimespans() as $span) {
					if (!$span->getAccessUntil()) {
						$span->setAccessUntil(new \DateTime("now"));
					}
				}
			}

			// remove all rights to this conversation
			foreach ($meta->getRights() as $right) {
				$meta->removeRight($right);
			}
		}
	}


	public function updateMembers(Conversation $conversation) {
		$realm = $conversation->getAppReference();
		$added = 0;
		$removed = 0;

		if ($realm) {
			$members = $realm->findMembers();

			$query = $this->em->createQuery('SELECT u FROM MsgBundle:User u WHERE u.app_user IN (:members)');
			$query->setParameter('members', $members->toArray());
			$users = new ArrayCollection($query->getResult());

			$query = $this->em->createQuery('SELECT u FROM MsgBundle:User u JOIN u.conversations_metadata m WHERE m.conversation = :conversation');
			$query->setParameter('conversation', $conversation);
			$participants = new ArrayCollection($query->getResult());

			foreach ($users as $user) {
				if (!$participants->contains($user)) {
					// this user is missing from the conversation, but should be there - add him
					$this->addParticipant($conversation, $user);
					$added++;
				}
			}

			foreach ($participants as $part) {
				if (!$users->contains($part)) {
					// this user is in the conversation, but shouldn't - remove him
					$this->removeParticipant($conversation, $part);
					$removed++;
				}
			}

			// TODO: make sure owner and ruler are identical
			
		}
		return array('added'=>$added, 'removed'=>$removed);
	}


	private function equal(User $a, User $b) {
		echo $a->getName()." = ".$b->getName()." ?";
		if ($a==$b) {
			echo "true";
		} else {
			echo "false";
		}
		echo "\n";
		return $a == $b;
	}
}
