<?php

namespace tesdir;


class TestClass extends TestCase
{

    /**
     * @feature feature 66
     * @scenario initialize project
     * @case Successful connection
     * @test
     */
    public function validateCredentials_successful()
    {
        //Given

        //When
        $response = $this->validateCredentials(123);

        //Then
        $this->assertInstanceOf(Status::class, $response);
    }

    /**
     * @feature Project Update
     * @scenario Project Delete
     * @case Successfully end
     * @test
     */
    public function remove_successful()
    {
        //Given

        //When
        $response = $this->remove(123);

        //Then
        $this->assertInstanceOf(Status::class, $response);
    }

    /**
     * @feature feature 66
     * @scenario initialize project
     * @case Failed connection to the code repository
     * @test
     */
    public function validateCredentials_failed()
    {
        //Given

        //When
        $response = $this->validateCredentials(123);

        //Then
        $this->assertInstanceOf(Error::class, $response);
    }

    /**
     * @feature feature 66
     * @scenario initialize project
     * @case Successful connection
     * @test
     */
    public function validateCredentials_successful2()
    {
        //Given

        //When
        $response = $this->validateCredentials(1234);

        //Then
        $this->assertInstanceOf(Status::class, $response);
    }

    }
