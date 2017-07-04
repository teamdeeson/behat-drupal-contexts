<?php

namespace TeamDeeson\Behat\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Mink\Exception\ElementTextException;
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

  /**
   * Finds and navigates to content of a given type with a given title.
   *
   * @When I am viewing a/an existing :type :entity_type with the title :title
   */
  public function viewNode($type, $title, $entity_type) {
    $entityStorage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $query = $entityStorage->getQuery();

    $entityType = $entityStorage->getEntityType();
    $results = $query->condition($entityType->getKey('bundle'), $type)
      ->condition($entityType->getKey('label'), $title)
      ->execute();

    if (empty($results)) {
      throw new \Exception("No {$type} with the title '{$title}' could be found");
    }

    $entity = $entityStorage->load(end($results));
    $url = $entity->toUrl();
    $path = $url->getInternalPath();
    $this->assertAtPath($url->toString());
  }

  /**
   * Checks, that page contains specified text within a parent element with a
   * given css selector.
   * Example: Then I should see "Who is the Batman?" rendered as "block"
   * Example: And I should see "Who is the Batman?" rendered as "block"
   *
   * @Then /^(?:|I )should see "(?P<text>(?:[^"]|\\")*)" rendered as (?P<selector>(?:[^"]|\\")*)$/
   */
  public function assertAtLeastOneElementContainsText($text, $selector) {
    /** @var NodeElement[] $items */
    $items = $this->getSession()->getPage()->findAll('css', $selector);

    /** @var \Behat\Mink\Element\NodeElement $item */
    while ($item = array_shift($items)) {
      $regex = '/'.preg_quote($text, '/').'/ui';
      if (preg_match($regex, $item->getText())) {
        return;
      }
    }

    throw new \Exception("No {$selector} element containing '{$text}' could be found.'");
  }

  /**
   * @Then I wait :time miliseconds for the page to load
   */
  public function iWaitMilisecondsForPageToLoad($time) {
    $this->getSession()->wait($time);
  }

  /**
   * @Then I wait for the page to load
   */
  public function iWaitForPageToLoad() {
    $this->iWaitMilisecondsForPageToLoad(500);
  }

}
