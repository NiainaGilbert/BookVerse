<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookController extends Controller
{
    public function index(Request $request)
    {

        //condition du requete(Where)
        $condition = [];
        $param = [];

        if($request->filled('search'))
        {
            $search = '%' . $request->search . '%';
            $condition[] =  "(books.title LIKE ? OR books.description LIKE ? OR authors.author_name LIKE ? OR book_detail.isbn13 LIKE ?OR book_detail.isbn13 LIKE ?)";
            $param[] = $search;
            $param[] = $search;
            $param[] = $search;
            $param[] = $search;
        }

        if($request->filled('year_min'))
        {
            $condition[] = "books.published_year >= ?";
            $param[] = $request->year_min;
        }

        if($request->filled('year_max'))
        {
            $condition[] = "books.published_year <= ?";
            $param[] = $request->year_max;
        }

        if($request->filled('min_rating'))
        {
            $condition[] = "books.average_rating >= ?";
            $param[] = $request->min_rating;
        }

        if($request->filled('min_pages'))
        {
            $condition[] = "books.num_pages >= ?";
            $param[] = $request->min_pages;
        }

        if($request->filled('max_pages'))
        {
            $condition[] = "books.num_pages <= ?";
            $param[] = $request->max_pages;
        }

        //par catégorie
        $categoryFilter = "";
        if($request->filled('categories'))
        {
            $categories = is_array($request->categories) ? $request->categories : explode(',', $request->categories);
            $placeholder = implode(',', array_fill(0, count($categories), '?'));
            $categoryFilter = "AND books.id IN (SELECT book_id FROM categories WHERE category_name IN ({$placeholders})";
            $param = array_merge($param, $categories);  
        }

        $whereClause = count($condition) > 0 ? implode(" AND ", $condition) : "";

        // Triage
        $sortBy = $request->get('sort_by', 'popularity_score');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['title', 'average_rating', 'published_year', 'num_pages', 'popularity_score', 'ratings_count'];
        
        if(!in_array($sortBy, $allowedSorts))
            $sortBy = 'popularity_score';
        if (!in_array(strtolower($sortOrder), ['asc', 'desc']))
            $sortOrder = 'desc';
        
        $orderByClause = "ORDER BY books.{$sortBy} {$sortOrder}";
        
        //Pagination
        $perPage = (int)$request->get('per_page', 12);
        $page = (int)$request->get('page', 1);
        $offset = ($page - 1) * $perPage;

        //Requete
        $sql = "
            SELECT 
                books.*,
                GROUP_CONCAT(DISTINCT authors.author_name SEPARATOR ', ') as authors,
                GROUP_CONCAT(DISTINCT categories.category_name SEPARATOR ', ') as categories,
                book_detail.isbn13
            FROM books
            LEFT JOIN authors ON books.id = authors.books_id
            LEFT JOIN categories ON books.id = categories.book_id
            LEFT JOIN book_detail ON books.id = book_detail.book_id
            {$whereClause}
            {$categoryFilter}
            GROUP BY books.id, books.title, books.thumbnail_url, books.description,
                     books.published_year, books.average_rating, books.ratings_count,
                     books.num_pages, books.popularity_score, book_detail.isbn13
            {$orderByClause}
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $books = DB::select($sql, $param);


         //Total
        $countSql = "SELECT COUNT(DISTINCT books.id) as total FROM books";
        if ($whereClause || $categoryFilter) {
            $countSql = "
                SELECT COUNT(DISTINCT books.id) as total
                FROM books
                LEFT JOIN authors ON books.id = authors.books_id
                LEFT JOIN categories ON books.id = categories.book_id
                LEFT JOIN book_detail ON books.id = book_detail.book_id
                {$whereClause}
                {$categoryFilter}
            ";
        }
        
        $totalResult = DB::select($countSql, $param);
        $total = $totalResult[0]->total;

        return response()->json([
            'data' => $books,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage)
        ]);
        return ;
    }
    

    public function show($id)
    {
        $sql = "
            SELECT books.*, book_detail.isbn13
            FROM books
            LEFT JOIN book_detail ON books.id = book_detail.book_id
            WHERE books.id = ?
            LIMIT 1
        ";
        $books = DB::select($sql, [$id]);

        if(empty($books))
            return response()->json(['error' => 'Book not found'], 404);

        $book = $books[0];

        //Auteur
        $authorsSql = "SELECT author_name FROM authors WHERE books_id = ?";
        $authors = DB::select($authorsSql, [$id]);
        $book->authors = array_column($authors, 'author_name');

        //Categorie
        $categorySql = "SELECT category_name FROM categories WHERE book_id = ?";
        $categories = DB::select($categorySql, [$id]);
        $book->categories = array_column($categories, 'category_name');

        return response()->json($book);
    }

    //
    public function stats()
    {
        $stats = [];

        //Total de livre
        $totalBooks = DB::select("SELECT COUNT(*) as total FROM books");
        $stats['total_books'] = $totalBooks[0]->total;
        
        //Note moyen
        $avg_rating = DB::select("SELECT AVG(average_rating) as avg FROM books");
        $stats['average_rating'] = round($avgRating[0]->avg, 2);

        //Total d'auteur
        $totalAuthors = DB::select("SELECT COUNT(DISTINCT author_name) as total FROM authors");
        $stats['total_authors'] = $totalAuthors[0]->total;

        //Total categories
        $totalCategories = DB::select("SELECT COUNT(DISTINCT category_name) as total FROM categories");
        $stats['total_categories'] = $totalCategories[0]->total;

        //Best rating livre
        $topRatedSql = "
            SELECT
                books.*,
                GROUP_CONCAT(authors.author_name SEPARATOR ', ') as authors
            FROM books
            LEFT JOIN authors ON books.id = authors.books_id
            GROUP BY books.id, books.title, books.thumbnail_url, books.description,
                     books.published_year, books.average_rating, books.ratings_count,
                     books.num_pages, books.popularity_score
            ORDER BY books.average_rating DESC
            LIMIT 1

        ";
        $topRated = DB::select($topRatedSql);
        $stats['top_rated_book'] = !empty($topRated) ? topRated[0] : null;

        $mostPopularSql = "
            SELECT
                books.*,
                GROUP_CONCAT(authors.author_name SEPARATOR ', ') as authors
            FROM books
            LEFT JOIN authors ON books.id = authors.books_id
            
        ";
        return;
    }
}
?>