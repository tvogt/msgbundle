<?php

namespace Calitarus\MessagingBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Doctrine\Common\Collections\ArrayCollection;


use Calitarus\MessagingBundle\Entity\Conversation;
use Calitarus\MessagingBundle\Entity\ConversationMetadata;
use Calitarus\MessagingBundle\Entity\User;


/**
 * @Route("/manage")
 */
class ManageController extends Controller {

	/**
		* @Route("/participants/{meta}", name="cmsg_participants", requirements={"meta"="\d+"})
		* @Template
		*/
	public function participantsAction(ConversationMetadata $meta) {
		$user = $this->get('message_manager')->getCurrentUser();

		if ($meta->getUser() != $user) {
			throw $this->createAccessDeniedException($this->get('translator')->trans('error.conversation.noaccess', array(), "MsgBundle"));
		}

		$metas = $meta->getConversation()->getMetadata();

		$em = $this->getDoctrine()->getManager();
		$rights = $em->getRepository('MsgBundle:Right')->findAll();

		return array('metas'=>$metas, 'rights'=>$rights);
	}

	/**
		* @Route("/conversation/leave", name="cmsg_leave", defaults={"_format"="json"})
		*/
	public function leaveAction(Request $request) {
		$user = $this->get('message_manager')->getCurrentUser();

		$id = $request->request->get('id');

		$meta = $this->getDoctrine()->getManager()->getRepository('MsgBundle:ConversationMetadata')->find($id);
		if (!$meta || $meta->getUser() != $user) {
			throw $this->createAccessDeniedException($this->get('translator')->trans('error.conversation.noaccess', array(), "MsgBundle"));
		}

		$convos =  $this->get('message_manager')->leaveConversation($meta, $user);

		$this->getDoctrine()->getManager()->flush();
		
		return new Response(json_encode($convos));
	}

}
