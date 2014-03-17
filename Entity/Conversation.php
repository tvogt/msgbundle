<?php 

namespace Calitarus\MessagingBundle\Entity;

class Conversation {

	public function findMeta(User $user) {
		return $this->getMetadata()->filter(
			function($entry) use ($user) {
				return ($entry->getUser() == $user);
			}
		)->first();
	}


	public function getTopic() {
		if ($this->getAppReference()) {
			return $this->getAppReference()->getName();
		} else {
			return $this->topic;
		}
	}

}
