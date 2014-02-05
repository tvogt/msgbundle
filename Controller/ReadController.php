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


/**
 * @Route("/read")
 */
class ReadController extends Controller {

	/**
		* @Route("/summary", name="cmsg_summary")
		* @Template
		*/
	public function summaryAction() {
		$user = $this->get('message_manager')->getCurrentUser();

		$new = array('messages' => 0, 'conversations' => 0);
		foreach ($user->getConversationsMetadata() as $meta) {
			if ($meta->getUnread() > 0) {
				$new['messages'] += $meta->getUnread();
				$new['conversations']++;
			}
		}

		return array('new' => $new);
	}


	/**
		* @Route("/unread", name="cmsg_unread")
		* @Template
		*/
	public function unreadAction() {
		$user = $this->get('message_manager')->getCurrentUser();

		$unread = new ArrayCollection;
		foreach ($user->getConversationsMetadata() as $meta) {
			if ($meta->getUnread() > 0) {
				$unread->add($meta);
			}
		}

		return array('unread' => $unread);
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
			throw new AccessDeniedHttpException($this->get('translator')->trans('error.conversation.noaccess'));
		}

		$data = $this->get('message_manager')->getConversation($meta);

		return array('data' => $data);
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

		return array('messages' => $messages, 'hide' => $source);
	}

}
