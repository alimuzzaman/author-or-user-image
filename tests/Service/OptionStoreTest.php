<?php
namespace AuthorImage\Tests\Service;

use AuthorImage\Service\OptionStore;
use PHPUnit\Framework\TestCase;

/**
 * Test cases for OptionStore service
 */
class OptionStoreTest extends TestCase
{
    private OptionStore $optionStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->optionStore = new OptionStore();
        
        // Reset global test options
        global $_test_options;
        $_test_options = [];
    }

    public function testGetBannedUsersReturnsEmptyArrayByDefault(): void
    {
        $banned = $this->optionStore->getBannedUsers();
        
        $this->assertIsArray($banned);
        $this->assertEmpty($banned);
    }

    public function testAddBannedUserAddsUserToList(): void
    {
        $result = $this->optionStore->addBannedUser('testuser');
        
        $this->assertTrue($result);
        $this->assertTrue($this->optionStore->isUserBanned('testuser'));
    }

    public function testAddBannedUserReturnsFalseForDuplicate(): void
    {
        $this->optionStore->addBannedUser('testuser');
        $result = $this->optionStore->addBannedUser('testuser');
        
        $this->assertFalse($result);
    }

    public function testRemoveBannedUserRemovesUserFromList(): void
    {
        $this->optionStore->addBannedUser('testuser');
        
        $result = $this->optionStore->removeBannedUser('testuser');
        
        $this->assertTrue($result);
        $this->assertFalse($this->optionStore->isUserBanned('testuser'));
    }

    public function testRemoveBannedUserReturnsFalseForNonExistentUser(): void
    {
        $result = $this->optionStore->removeBannedUser('nonexistent');
        
        $this->assertFalse($result);
    }

    public function testIsUserBannedReturnsFalseForNonBannedUser(): void
    {
        $result = $this->optionStore->isUserBanned('testuser');
        
        $this->assertFalse($result);
    }

    public function testGetImageSizeReturnsDefaultSize(): void
    {
        $size = $this->optionStore->getImageSize();
        
        $this->assertIsArray($size);
        $this->assertEquals('150', $size['h']);
        $this->assertEquals('150', $size['width']);
    }

    public function testUpdateImageSizeUpdatesSize(): void
    {
        $newSize = ['h' => '200', 'width' => '200'];
        
        $result = $this->optionStore->updateImageSize($newSize);
        
        $this->assertTrue($result);
        $this->assertEquals($newSize, $this->optionStore->getImageSize());
    }

    public function testGetNotificationStatusReturnsDefault(): void
    {
        $status = $this->optionStore->getNotificationStatus();
        
        $this->assertFalse($status);
    }

    public function testUpdateNotificationStatusUpdatesStatus(): void
    {
        $result = $this->optionStore->updateNotificationStatus(1);
        
        $this->assertTrue($result);
        $this->assertEquals(1, $this->optionStore->getNotificationStatus());
    }

    public function testMultipleBannedUsersHandledCorrectly(): void
    {
        $this->optionStore->addBannedUser('user1');
        $this->optionStore->addBannedUser('user2');
        $this->optionStore->addBannedUser('user3');
        
        $banned = $this->optionStore->getBannedUsers();
        
        $this->assertCount(3, $banned);
        $this->assertContains('user1', $banned);
        $this->assertContains('user2', $banned);
        $this->assertContains('user3', $banned);
    }

    public function testRemoveMiddleUserFromBannedList(): void
    {
        $this->optionStore->addBannedUser('user1');
        $this->optionStore->addBannedUser('user2');
        $this->optionStore->addBannedUser('user3');
        
        $this->optionStore->removeBannedUser('user2');
        
        $banned = $this->optionStore->getBannedUsers();
        
        $this->assertCount(2, $banned);
        $this->assertContains('user1', $banned);
        $this->assertContains('user3', $banned);
        $this->assertNotContains('user2', $banned);
    }
}
