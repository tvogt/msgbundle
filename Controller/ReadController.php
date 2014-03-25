<?php

namespace Calitarus\MessagingBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Doctrine\Common\Collections\ArrayCollection;


use Calitarus\MessagingBundle\Entity\ConversationMetadata;
use Calitarus\MessagingBundle\Entity\User;


/**
 * @Route("/read")
 */
class ReadController extends Controller {

	/**
		* @Route("/", name="cmsg_index")
		* @Template
		*/
	public function indexAction() {
		$metas = $this->get('message_manager')->getToplevelConversationsMeta();

		return array('conversations' => $metas);
	}

	/**
		* @Route("/summary", name="cmsg_summary")
		* @Template
		*/
	public function summaryAction() {
		$user = $this->get('message_manager')->getCurrentUser();

		$total = 0;
		$new = array('messages' => 0, 'conversations' => 0);
		foreach ($user->getConversationsMetadata() as $meta) {
			$total++;
			if ($meta->getUnread() > 0) {
				$new['messages'] += $meta->getUnread();
				$new['conversations']++;
			}
		}

		return array(
			'total' => $total,
			'new' => $new,
			'unread' => $this->get('message_manager')->getUnreadMessages($user),
			'local_news' => $this->get('news_manager')->getLocalList($user->getAppUser())
		);
	}


	/**
		* @Route("/unread", name="cmsg_unread")
		* @Template
		*/
	public function unreadAction() {
		$user = $this->get('message_manager')->getCurrentUser();

		return array('unread' => $this->get('message_manager')->getUnreadMessages($user));
	}

	/**
		* @Route("/contacts", name="cmsg_contacts")
		* @Template
		*/
	public function contactsAction() {
		return array('contacts' => $this->get('message_manager')->getContactsList());
	}


	/**
		* @Route("/conversation/{meta}", name="cmsg_conversation", requirements={"meta"="\d+"})
		* @Template
		*/
	public function conversationAction(ConversationMetadata $meta) {
		$user = $this->get('message_manager')->getCurrentUser();

		if ($meta->getUser() != $user) {
			throw new AccessDeniedHttpException($this->get('translator')->trans('error.conversation.noaccess', array(), "MsgBundle"));
		}

		$last = $meta->getLastRead();
		if ($last==null) {
			// if this is null, we've never read this conversation, so everything is new
			$last = new \DateTime('2014-01-01');
		}
		$data = $this->get('message_manager')->getConversation($meta);

		// flush to update our read status
		$this->getDoctrine()->getManager()->flush();

		$veryold = new \DateTime('now');
		$veryold->sub(new \DateInterval("P7D")); // TODO: make this user-configurable

		return array(
			'meta' => $meta,
			'last' => $last,
			'data' => $data,
			'veryold' => $veryold
		);
	}


	/**
		* @Route("/related", name="cmsg_related")
		* @Template
		*/
	public function relatedAction(Request $request) {
		$user = $this->get('message_manager')->getCurrentUser();

		/* TODO: check access rights */

		$id = $request->query->get('id');
		$type = $request->query->get('type');

		$source = $this->getDoctrine()->getManager()->getRepository('MsgBundle:Message')->find($id);
		$messages = new ArrayCollection;
		if ($type=='source') {
			$related = $source->getRelatedToMe();
			foreach ($related as $rel) {
				$messages->add($rel->getSource());
			}
		} else {
			$related = $source->getRelatedMessages();
			foreach ($related as $rel) {
				$messages->add($rel->getTarget());
			}
		}

		// TODO: modify the counter on the conversation now that we're showing the messages... - but for that we might have to know not only how many, but also which messages are unread...

		return array('messages' => $messages, 'hide' => $source);
	}

}
