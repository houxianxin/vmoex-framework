<?php

/**
 * This file is part of project yeskn-studio/wpcraft.
 *
 * Author: Jaggle
 * Create: 2018-07-04 22:06:45
 */

namespace Yeskn\MainBundle\Command;

use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Yeskn\MainBundle\Services\AllocateSpaceService;

class BlogCreateCommand extends ContainerAwareCommand
{
    private $url;

    /**
     * @var OutputInterface
     */
    private $output;

    private $username;

    protected function configure()
    {
        $this->setName('blog:create');
        $this->addOption('username', null, InputOption::VALUE_REQUIRED);
        $this->addOption('password', null, InputOption::VALUE_REQUIRED);
        $this->addOption('email', null, InputOption::VALUE_REQUIRED);
        $this->addOption('blogName', null, InputOption::VALUE_REQUIRED);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $container = $this->getContainer();
        $allocate = new AllocateSpaceService($container);

        $connection = $container->get('doctrine')->getConnection();

        $this->username = $username = $input->getOption('name');
        $password = $input->getOption('password');
        $blogName = $input->getOption('blogName');
        $email = $input->getOption('email');

        $connection->executeQuery("create database wpcast_{$username};");

        $this->writeln('创建数据库成功...');

        $sql = "grant all privileges on wpcast_{$username}.* to {$username}@localhost identified by '{$password}';flush privileges;";

        $connection->executeQuery($sql);

        $this->writeln('创建数据库用户成功...');

        $webPath = $allocate->allocateWebSpace($username);

        $this->writeln('分配服务器空间成功...');

        $allocate->allocateDbSpace($username);

        $this->writeln('分配数据库空间成功...');

        $fs = new Filesystem();

        $config = $container->getParameter('wpcast');

        $fs->mirror($config['wordpress_source'], $webPath);

        $this->writeln('复制wordpress代码成功...');

        $fs->chown($webPath, $config['server_user'], true);

        $this->initDatabase($username, $blogName, $password, $email);

        $this->writeln('博客初始化成功！');

        $this->writeln('地址：'. $this->url);
        $this->writeln('用户名：'. $username);
        $this->writeln('标题：'. $username . '的博客');
        $this->writeln('密码：'. $password);
        $this->writeln('邮箱：'. $email);
    }

    public function initDatabase($name, $title, $pass, $email)
    {
        $this->url = $url = "https://{$name}." . $this->getContainer()->getParameter('domain');
        $client = new Client(['verify' => false]);

        $client->post($url . '/wp-admin/setup-config.php?step=2', [
            'form_params' => [
                'dbname' => 'wpcast_'.$name,
                'uname' => $name,
                'pwd' => $pass,
                'dbhost' => 'localhost',
                'prefix' => 'wp_',
                'language' => 'zh_CN',
                'submit' => '提交'
            ]
        ]);

        $client->post($url . '/wp-admin/install.php?step=2', [
            'form_params' => [
                'weblog_title' => $title,
                'user_name' => $name,
                'admin_password' => $pass,
                'pass1-text' => $pass,
                'admin_password2' => $pass,
                'pw_weak' => 'on',
                'admin_email' => $email,
                'Submit' => '安装WordPress',
                'language' => 'zh_CN'
            ]
        ]);
    }

    protected function writeln($msg)
    {
        $pushService = $this->getContainer()->get('socket.push');

        $pushService->pushCreateBlogEvent($this->username, $msg);
    }
}