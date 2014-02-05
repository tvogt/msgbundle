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

use Calitarus\MessagingBundle\Entity\Message;

use Calitarus\MessagingBundle\Form\NewConversationType;
use Calitarus\MessagingBundle\Form\MessageReplyType;


/**
 * @Route("/write")
 */
class WriteController extends Controller {

	/**
		* @Route("/new_conversation", name="cmsg_new_conversation")
		* @Template
		*/
	public function newconversationAction(Request $request) {
		$user = $this->get('message_manager')->getCurrentUser();

		$character = $this->get('appstate')->getCharacter();
		if ($character->getAvailableEntourageOfType("herald")->isEmpty()) {
			$distance = $this->get('geography')->calculateInteractionDistance($character);
		} else {
			$distance = $this->get('geography')->calculateSpottingDistance($character);
		}

		$contacts = $this->get('message_manager')->getContactsList();
		$form = $this->createForm(new NewConversationType($contacts, $distance, $character));

		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();

			$recipients = new ArrayCollection;
			foreach ($data['nearby'] as $rec) {
				$recipients->add($this->get('message_manager')->getMsgUser($rec));
			}
			if (isset($data['contacts'])) foreach ($data['contacts'] as $rec) {
				$recipients->add($rec);
			}
			$this->get('message_manager')->newConversation($user, $recipients, $data['topic'], $data['content'], false, $data['parent']);
			$this->getDoctrine()->getManager()->flush();
			return $this->redirect($this->get('router')->generate('cmsg_summary'));
		}

		return array(
			'form' => $form->createView()
		);
	}


	/**
		* @Route("/reply", name="cmsg_reply")
		* @Template
		*/
	public function replyAction(Request $request) {
		$user = $this->get('message_manager')->getCurrentUser();

		$form = $this->createForm(new MessageReplyType());

		$form->handleRequest($request);
		if ($form->isValid()) {
			$data = $form->getData();
			$em = $this->getDoctrine()->getManager();

			$source = $em->getRepository('MsgBundle:Message')->find($data['reply_to']);
			$message = $this->get('message_manager')->writeReply($source, $user, $data['content']);
			$em->flush();
			return $this->redirect($this->get('router')->generate('cmsg_summary'));
		}

		return array(
			'form' => $form->createView()
		);
	}

}
