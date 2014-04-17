<?php

namespace Calitarus\MessagingBundle\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\Common\Collections\Criteria;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Doctrine\Common\Collections\ArrayCollection;


use BM2\SiteBundle\Service\AppState;
use BM2\SiteBundle\Entity\Character;
use BM2\SiteBundle\Entity\Realm;

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

	public function getContactsList(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }

		$query = $this->em->createQuery('SELECT DISTINCT u FROM MsgBundle:User u JOIN u.conversations_metadata m1 JOIN m1.conversation c JOIN c.metadata m2 where m2.user = :me AND u != :me');
		$query->setParameter('me', $user);
		return $query->getResult();
	}

	public function getConversation(ConversationMetadata $m) {
		$qb = $this->em->createQueryBuilder();
		$qb->select('c, msg, meta')
			->from('MsgBundle:Conversation', 'c')
			->join('c.metadata', 'm')
			->leftJoin('c.messages', 'msg')
			->leftJoin('msg.metadata', 'meta')
			->where('m = :m')->setParameter('m', $m)
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('msg.id'),
				$qb->expr()->eq('msg.depth', 0),
				$qb->expr()->gt('msg.ts', 'm.last_read')
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('meta.id'),
				$qb->expr()->eq('meta.user', 'm.user')
		));

		$qb->orderBy('msg.ts', 'ASC');
		$query = $qb->getQuery();

		// set read status
		$m->setUnread(0)->setLastRead(new \DateTime("now"));

		return $query->getResult();
	}

	public function getToplevelConversationsMeta(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }

		/* TODO: this should be sorted by the last message posted to the conversation */
		$query = $this->em->createQuery('SELECT m,c FROM MsgBundle:ConversationMetadata m JOIN m.conversation c WHERE m.user = :me AND c.parent IS NULL ORDER BY c.app_reference ASC');
		$query->setParameter('me', $user);
		return $query->getResult();
	}


	public function leaveConversation(ConversationMetadata $meta, User $user, $ids=array()) {
		$ids[] = $meta->getId();

		$conversation = $meta->getConversation();
		foreach ($conversation->getChildren() as $child) {
			if ($meta = $child->findMeta($user)) {
				$ids = $this->leaveConversation($meta, $user, $ids);
			}
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


	public function getUnreadMessages(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }
		$unread = new ArrayCollection;
		foreach ($user->getConversationsMetadata() as $meta) {
			if ($meta->getUnread() > 0) {
				$unread->add($meta);
			}
		}
		return $unread;
	}




	/* creation methods */
	
	public function createConversation(User $creator, $topic, Conversation $parent=null, Realm $realm=null) {
		$conversation = new Conversation;
		$conversation->setTopic($topic);
		if ($parent) {
			$conversation->setParent($parent);
			$parent->addChildren($conversation);
		}
		if ($realm) {
			$conversation->setAppReference($realm);
		}
		$this->em->persist($conversation);

		$meta = new ConversationMetadata;
		$meta->setUnread(0);
		$meta->setConversation($conversation);
		$meta->setUser($creator);

		$owner = $this->em->getRepository('MsgBundle:Right')->findOneByName('owner');
		$meta->addRight($owner);

		$conversation->addMetadata($meta);
		$creator->addConversationsMetadata($meta);
		$this->em->persist($meta);

		return array($meta, $conversation);
	}

	public function newConversation(User $creator, $recipients, $topic, $content, Conversation $parent=null, Realm $realm=null) {
		list($creator_meta, $conversation) = $this->createConversation($creator, $topic, $parent, $realm);

		if ($realm) {
			$members = array();
			foreach ($realm->findMembers() as $m) {
				$members[] = $m->getId();
			}
			$query = $this->em->createQuery('SELECT u FROM MsgBundle:User u WHERE u.app_user IN (:members)');
			$query->setParameter('members', $members);
			$recipients = $query->getResult();
		}

		foreach ($recipients as $recipient) {
			if ($recipient != $creator) { // because he has already been added above
				$meta = new ConversationMetadata;
				$meta->setUnread(0);
				$meta->setConversation($conversation);
				$meta->setUser($recipient);
				$conversation->addMetadata($meta);
				$recipient->addConversationsMetadata($meta);
				$this->em->persist($meta);
			}
		}

		$message = $this->writeMessage($conversation, $creator, $content, 0);
		$this->em->flush();
		return array($creator_meta,$message);
	}

	public function writeMessage(Conversation $conversation, User $author=null, $content="(empty)", $depth=0) {
		$msg = new Message;
		$msg->setSender($author);
		$msg->setContent($content);
		$msg->setConversation($conversation);
		$msg->setTs(new \DateTime("now"));
		$msg->setCycle($this->appstate->getCycle());
		$msg->setDepth($depth);
		$this->em->persist($msg);
		$conversation->addMessage($msg);

		// now increment the unread counter for everyone except the author
		foreach ($conversation->getMetadata() as $reader) {
			if ($reader->getUser() != $author) {
				$reader->setUnread($reader->getUnread()+1);
			}
		}

		return $msg;
	}

	public function writeReply(Message $source, User $author, $content) {
		$msg = $this->writeMessage($source->getConversation(), $author, $content, $source->getDepth()+1);

		$rel = new MessageRelation;
		$rel->setType('response');
		$rel->setSource($source);
		$rel->setTarget($msg);
		$this->em->persist($rel);

		return $msg;
	}

	public function writeSplit(Message $source, User $author, $topic, $content) {
		// set our recipients to be identical to the ones of the old conversation
		$recipients = new ArrayCollection;
		foreach ($source->getConversation()->getMetadata() as $m) {
			if ($m->getUser() != $author) {
				$recipients->add($m->getUser());
			}
		}

		list($meta,$msg) = $this->newConversation($author, $recipients, $topic, $content, $source->getConversation());

		// inherit app_reference
		if ($ref = $source->getConversation()->getAppReference()) {
			$meta->getConversation()->setAppReference($ref);
		}

		$rel = new MessageRelation;
		$rel->setType('response');
		$rel->setSource($source);
		$rel->setTarget($msg);
		$this->em->persist($rel);

		return $meta;
	}


	public function addMessage(Conversation $conversation, User $author, $content) {
		$msg = $this->writeMessage($conversation, $author, $content, 0);

		return $msg;
	}


	/* management methods */
	
	// you might want to change $time_limit to false if you don't use it or only rarely.
	public function addParticipant(Conversation $conversation, User $participant) {
		$meta = new ConversationMetadata;
		$meta->setConversation($conversation);
		$meta->setUser($participant);
		$conversation->addMetadata($meta);
		$meta->setUnread($conversation->getMessages()->count());
		$this->em->persist($meta);
	}

	public function removeParticipant(Conversation $conversation, User $participant) {
		$meta = $conversation->findMeta($participant);
		if ($meta) {
			// remove from conversation
			$meta->getConversation()->removeMetadata($meta);
			$meta->getUser()->removeConversationsMetadata($meta);
			$this->em->remove($meta);
		}
	}


	public function updateMembers(Conversation $conversation) {
		$realm = $conversation->getAppReference();
		$added = 0;
		$removed = 0;

		if ($realm) {
			$members = $realm->findMembers();

			if ($members && !$members->isEmpty()) {
				$query = $this->em->createQuery('SELECT u FROM MsgBundle:User u WHERE u.app_user IN (:members)');
				$query->setParameter('members', $members->toArray());
				$users = new ArrayCollection($query->getResult());

				$query = $this->em->createQuery('SELECT u FROM MsgBundle:User u JOIN u.conversations_metadata m WHERE m.conversation = :conversation');
				$query->setParameter('conversation', $conversation);
				$participants = new ArrayCollection($query->getResult());

				foreach ($users as $user) {
					if (!$participants->contains($user)) {
						// this user is missing from the conversation, but should be there
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
			}			
		}
		return array('added'=>$added, 'removed'=>$removed);
	}

	public function setAllUnread(User $user=null) {
		if (!$user) { $user=$this->getCurrentUser(); }

		foreach ($user->getConversationsMetadata() as $meta) {
			$count = $meta->getConversation()->getMessages()->count();
			$meta->setUnread($count);
			$meta->setLastRead(null);
		}
	}

}
