<?php
namespace AuthorImage\Tests\Service;

use AuthorImage\Service\FileManager;
use PHPUnit\Framework\TestCase;

/**
 * Test cases for FileManager service
 */
class FileManagerTest extends TestCase
{
    private FileManager $fileManager;
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileManager = new FileManager();
        
        // Create test directory
        $this->testDir = sys_get_temp_dir() . '/test-author-image-' . uniqid();
        mkdir($this->testDir, 0777, true);
        
        // Update wp_upload_dir to use test directory
        global $_test_upload_dir;
        $_test_upload_dir = $this->testDir;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test directory
        if (file_exists($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testImageExistsReturnsFalseForNonExistentImage(): void
    {
        $result = $this->fileManager->imageExists('testuser');
        
        $this->assertFalse($result);
    }

    public function testImageExistsReturnsTrueForExistingImage(): void
    {
        // Create test image
        $authorImageDir = $this->testDir . '/author-image';
        mkdir($authorImageDir, 0777, true);
        touch($authorImageDir . '/testuser.jpg');
        
        $result = $this->fileManager->imageExists('testuser');
        
        $this->assertTrue($result);
    }

    public function testDeleteImageReturnsFalseForNonExistentImage(): void
    {
        $result = $this->fileManager->deleteImage('nonexistent');
        
        $this->assertFalse($result);
    }

    public function testDeleteImageRemovesExistingImage(): void
    {
        // Create test image
        $authorImageDir = $this->testDir . '/author-image';
        mkdir($authorImageDir, 0777, true);
        $imagePath = $authorImageDir . '/testuser.jpg';
        touch($imagePath);
        
        $this->assertTrue(file_exists($imagePath));
        
        $result = $this->fileManager->deleteImage('testuser');
        
        $this->assertTrue($result);
        $this->assertFalse(file_exists($imagePath));
    }

    public function testGetImageUrlReturnsFalseForNonExistentImage(): void
    {
        $result = $this->fileManager->getImageUrl('nonexistent');
        
        $this->assertFalse($result);
    }

    public function testGetImageUrlReturnsUrlForExistingImage(): void
    {
        // Create test image
        $authorImageDir = $this->testDir . '/author-image';
        mkdir($authorImageDir, 0777, true);
        touch($authorImageDir . '/testuser.jpg');
        
        $result = $this->fileManager->getImageUrl('testuser');
        
        $this->assertIsString($result);
        $this->assertStringContainsString('/author-image/testuser.jpg', $result);
    }

    public function testGetAllImagesReturnsEmptyArrayForEmptyDirectory(): void
    {
        $result = $this->fileManager->getAllImages();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAllImagesReturnsImageList(): void
    {
        // Create test images
        $authorImageDir = $this->testDir . '/author-image';
        mkdir($authorImageDir, 0777, true);
        touch($authorImageDir . '/user1.jpg');
        touch($authorImageDir . '/user2.jpg');
        touch($authorImageDir . '/author_default.jpg');
        
        $result = $this->fileManager->getAllImages();
        
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains('user1', $result);
        $this->assertContains('user2', $result);
        $this->assertContains('author_default', $result);
    }

    public function testEnsureDirectoryExistsCreatesDirectory(): void
    {
        $authorImageDir = $this->testDir . '/author-image';
        
        $this->assertFalse(file_exists($authorImageDir));
        
        $result = $this->fileManager->ensureDirectoryExists();
        
        $this->assertTrue($result);
        $this->assertTrue(file_exists($authorImageDir));
        $this->assertTrue(is_dir($authorImageDir));
    }

    public function testEnsureDirectoryExistsReturnsTrueForExistingDirectory(): void
    {
        $authorImageDir = $this->testDir . '/author-image';
        mkdir($authorImageDir, 0777, true);
        
        $result = $this->fileManager->ensureDirectoryExists();
        
        $this->assertTrue($result);
    }
}
