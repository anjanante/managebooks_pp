<?php

namespace App\Controller;

use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class BookController extends AbstractController
{
    #[Route('/api/books', name: 'book', methods:['GET'])]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $nPage  = $request->get('page', 1);
        $nLimit = $request->get('limit', 3);

        $nIdCache = "getAllBooks-".$nPage."-".$nLimit;

        $jsonBookList    = $cache->get($nIdCache, function(ItemInterface $item) use ($bookRepository, $nPage, $nLimit, $serializer){
            $item->tag('booksCache');
            $bookList = $bookRepository->findAllWithPagination($nPage, $nLimit);
            return $serializer->serialize($bookList,'json', ['groups' => 'getBooks']);
        });

        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    #[Route('/api/books/{id}', name: 'detailBook', methods:['GET'])]
    public function getOneBook(Book $book, SerializerInterface $serializer): JsonResponse
    {
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook   = $serializer->serialize($book,'json', $context);
        return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
    }

    #[Route('/api/books/{id}', name: 'deleteBook', methods:['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'You are not the admin, sorry')]
    public function deleteOneBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cache): JsonResponse
    {
        $cache->invalidateTags(['booksCache']);
        $em->remove($book);
        $em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/books', name: 'createBook', methods:['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'You are not the admin, sorry')]
    public function createBook(Request $request, EntityManagerInterface $em, SerializerInterface $serializer, UrlGeneratorInterface $urlGenerator, AuthorRepository $authorRepository, ValidatorInterface $validator): JsonResponse
    {
        $book   = $serializer->deserialize($request->getContent(), Book::class, 'json');
        //set author
        $content    = $request->toArray();
        $idAuthor   = $content['idAuthor'] ?? -1;
        $book->setAuthor($authorRepository->find($idAuthor));

        $errors = $validator->validate($book);
        if($errors->count() > 0){
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
            // throw new HttpException(JsonResponse::HTTP_BAD_REQUEST, "La request is invalid"); //for subscriber
        }
        $em->persist($book);
        $em->flush();

        $jsonBook   = $serializer->serialize($book,'json', ['groups' => 'getBooks']);
        $location   = $urlGenerator->generate('detailBook', ['id' => $book->getid()], UrlGeneratorInterface::ABSOLUTE_PATH);
        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ['Location' => $location], true);
    }

    #[Route('/api/books/{id}', name: 'updateBook', methods:['PUT'])]
    #[IsGranted('ROLE_ADMIN', message: 'You are not the admin, sorry')]
    public function updateBook(Request $request, Book $currentBook, EntityManagerInterface $em, SerializerInterface $serializer,  AuthorRepository $authorRepository, ValidatorInterface $validator): JsonResponse
    {
        $book   = $serializer->deserialize($request->getContent(), Book::class, 'json', [AbstractNormalizer::OBJECT_TO_POPULATE => $currentBook]);

        $errors = $validator->validate($book);
        if($errors->count() > 0){
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
            // throw new HttpException(JsonResponse::HTTP_BAD_REQUEST, "La request is invalid"); //for subscriber
        }

        //set author
        $content    = $request->toArray();
        $idAuthor   = $content['idAuthor'] ?? -1;
        $book->setAuthor($authorRepository->find($idAuthor));

        $em->persist($book);
        $em->flush();
        
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
