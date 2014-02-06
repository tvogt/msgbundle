<?php 

namespace Calitarus\MessagingBundle\Entity;

class Conversation {

	/* FIXME: this won't work for multiple periods */
	public function findMeta(User $user) {
		return $this->getMetadata()->filter(
			function($entry) use ($user) {
				return ($entry->getUser() == $user);
			}
		)->first();
	}

}
