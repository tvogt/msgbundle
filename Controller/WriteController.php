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
/*
	FIXME: parent is disabled until fixed in NewConversationType
			$this->get('message_manager')->newConversation($user, $recipients, $data['topic'], $data['content'], false, $data['parent']);
*/
			$this->get('message_manager')->newConversation($user, $recipients, $data['topic'], $data['content']);
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

			// TODO: check for permissions 

			$source = $em->getRepository('MsgBundle:Message')->find($data['reply_to']);
			if ($source) {
				if ($data['conversation']>0) {
					// reply
					$message = $this->get('message_manager')->writeReply($source, $user, $data['content']);
				} else {
					// split-reply
					if (isset($data['topic']) && $data['topic']!="") {
						$topic = $data['topic'];
					} else {
						// no topic given so we increment the last one
						preg_match("/(.*) ([0-9]+)$/", $source->getConversation()->getTopic(), $matches);
						if ($matches) {
							$nr = intval($matches[2])+1;
							$topic = $matches[1]+" $nr";
						} else {
							$topic = $source->getConversation()->getTopic()." 2";
						}

					}
					// create the split
					$newmeta = $this->get('message_manager')->writeSplit($source, $user, $topic, $data['content']);
					return array('plain' => $this->get('router')->generate('cmsg_conversation', array('meta'=>$newmeta->getId())));
				}
			} else {
				$meta = $em->getRepository('MsgBundle:ConversationMetadata')->find($data['conversation']);
				if ($meta->getUser() == $user) {
					$message = $this->get('message_manager')->addMessage($meta->getConversation(), $user, $data['content']);
				} else {
					// TODO: error message
				}
			}
			$em->flush();
			return array('message' => $message);
		}

		return array(
			'form' => $form->createView()
		);
	}

}
