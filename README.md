# Doctrine Relations Loader

The library is a single class that allows you to load many related entities in an optimal way and performance.

Doctrine UnitOfWork's identityMap used for loading. So method "clear" in UnitOfWork breaks loading.

## Usage

Use blog structure with users, posts, comments and likes for example.

    /** @ORM\Entity() */
    class User
    {
        /**
         * @ORM\Id()
         * @ORM\Column(type="integer")
         */
        public $id;
    
        /** @ORM\Column(type="string") */
        public $name;
        
        /** @ORM\Column(type="string") */
        public $country;
    
        /** @ORM\OneToMany(targetEntity="Post", mappedBy="user") */
        public $posts;
    }
    
    /** @ORM\Entity() */
    class Post
    {
        /**
         * @ORM\Id()
         * @ORM\Column(type="integer")
         */
        public $id;
    
        /** @ORM\Column(type="string") */
        public $title;
    
        /** @ORM\Column(type="string") */
        public $content;
    
        /**
         * @ORM\ManyToOne(targetEntity="User")
         * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
         */
        public $user;
    
        /** @ORM\OneToMany(targetEntity="Comment", mappedBy="post") */
        public $comments;
    
        /** @ORM\OneToMany(targetEntity="Like", mappedBy="post") */
        public $likes;
    }
    
    /** @ORM\Entity() */
    class Comment
    {
        /**
         * @ORM\Id()
         * @ORM\Column(type="integer")
         */
        public $id;
    
        /** @ORM\Column(type="string") */
        public $content;
    
        /**
         * @ORM\ManyToOne(targetEntity="Post")
         * @ORM\JoinColumn(name="post_id", referencedColumnName="id")
         */
        public $post;
    
        /**
         * @ORM\ManyToOne(targetEntity="User")
         * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
         */
        public $user;
    }
    
    /**
     * @ORM\Entity()
     * @ORM\Table(name="`like`")
     */
    class Like
    {
        /**
         * @ORM\Id()
         * @ORM\Column(type="integer")
         */
        public $id;
    
        /**
         * @ORM\ManyToOne(targetEntity="Post")
         * @ORM\JoinColumn(name="post_id", referencedColumnName="id")
         */
        public $post;
    
        /**
         * @ORM\ManyToOne(targetEntity="User")
         * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
         */
        public $user;
    }


Now we count the number of countries from which we put Like for a specific user
(just for example, it is better to use the calculation in the database).

    $countries = [];
    $user = $this->em->getRepository(User::class)->find(1);
    foreach ($user->posts as $post) {
        foreach ($post->likes as $like) {
            $countries[$like->user->country] = 1;
        }
    }
    $num = count($countries);

By default, you will perform the number of queries to the database = number of posts + number of users + 2:

    SELECT * FROM user WHERE id = 1;
    SELECT * FROM post WHERE user_id = 1;
    SELECT * FROM `like` WHERE post_id = ?; # for each post
    SELECT * FROM user WHERE id = ?; # for unique user

Queries quantity is too large. I want to merge identical queries to the same table:
    
    SELECT * FROM `like` WHERE post_id IN (?); # for all post
    SELECT * FROM user WHERE id IN (?); # for all unique users

You can use eager loading, but it does not work with a large cascade of entities.
Another way to use RelationsLoader from this library.

    $countries = [];
    $user = $this->em->getRepository(User::class)->find(1);
    
    $relationsLoader = new RelationsLoader($this->em);
    $relationsLoader->load($user, ['posts' => ['likes' => ['user']]]);

    foreach ($user->posts as $post) {
        foreach ($post->likes as $like) {
            $countries[$like->user->country] = 1;
        }
    }
    $num = count($countries);

In this case, 4 queries to the database will be executed.

## Syntax

In `RelationsLoader::load`, you must pass the data for which you want to load the relations
(entity, array of entities, collection) and a hierarchical list of relationships to load.
How is formed a hierarchical list of consider examples.

- Loading posts for the users (similar to eager loading in doctrine):
        
        $relationsLoader->load($users, ['posts']);

- Loading posts and likes for posts:

        $relationsLoader->load($users, [
            'posts' => ['likes']
        ]);

- Loading posts, comments and likes for posts:

        $relationsLoader->load($users, [
            'posts' => [
                'comments',
                'likes'
            ]
        ]);

- Loading posts, comments and likes for posts, users for comments:

        $relationsLoader->load($users, [
            'posts' => [
                'comments' => ['user'],
                'likes'
            ]
        ]);

- Loading posts, comments and likes for posts, users for comments and likes:

        $relationsLoader->load($users, [
            'posts' => [
                'comments' => ['user'],
                'likes' => ['user']
            ]
        ]);
