<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Drupal\DrupalExtension\Context\MinkContext;

/**
 * Defines application features from the specific context.
 */
class AnonymousContext extends MinkContext implements Context, SnippetAcceptingContext {

  /**
   * @Then I should see an element with the :selector selector in the :regionName region
   *
   * @param string $selector
   *   The css selector.
   * @param string $regionName
   *   The region name.
   * @throws \Exception
   */
  public function assertSelectorInRegion($selector, $regionName) {
    $regionObj = $this->getRegion($regionName);

    $result = $regionObj->find('css', $selector);
    if (empty($result)) {
      throw new \Exception(sprintf('No class "%s" in the "%s" region on the page %s', $selector, $regionName, $this->getSession()->getCurrentUrl()));
    }
  }

}
