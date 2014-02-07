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


use Calitarus\MessagingBundle\Entity\Conversation;


/**
 * @Route("/manage")
 */
class ManageController extends Controller {

	/**
		* @Route("/participants/{conversation}", name="cmsg_participants", requirements={"conversation"="\d+"})
		* @Template
		*/
	public function participantsAction(Conversation $conversation) {
		$user = $this->get('message_manager')->getCurrentUser();

		// TODO
		$meta = $conversation->findMeta($user);
		if (!$meta) {
			throw new AccessDeniedHttpException($this->get('translator')->trans('error.conversation.noaccess'));
		}

		$metas = $conversation->getMetadata();

		$em = $this->getDoctrine()->getManager();
		$rights = $em->getRepository('MsgBundle:Right')->findAll();

		return array('metas'=>$metas, 'rights'=>$rights);
	}

}
