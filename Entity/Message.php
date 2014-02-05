<?php 

namespace Calitarus\MessagingBundle\Entity;

class Message {

	public function getRelatedMessagesExcept(Message $hide) {
		return $this->getRelatedMessages()->filter(
			function($entry) use ($hide) {
				return ($entry->getTarget() != $hide);
			}
		);
	}

	public function getRelatedToMeExcept(Message $hide) {
		return $this->getRelatedToMe()->filter(
			function($entry) use ($hide) {
				return ($entry->getSource() != $hide);
			}
		);
	}

}
